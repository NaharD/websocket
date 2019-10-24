<?php

    class WebServer
    {
        public $ws;
        public $http;

        public function __construct()
        {
            $wsHost = getenv('WS_HOST') ?: '0.0.0.0';
            $wsPort = getenv('WS_PORT') ?: 80;
            echo "WS server address - {$wsHost}:{$wsPort}\n";
            
            $this->ws = new swoole_websocket_server($wsHost, $wsPort);
            $this->prepareWs();
    
            $httpHost = getenv('HTTP_HOST') ?: '0.0.0.0';
            $httpPort = getenv('HTTP_PORT') ?: 8080;
            echo "HTTP server address - {$httpHost}:{$httpPort}\n";
            
            $this->http = $this->ws->addListener($httpHost, $httpPort, $type = SWOOLE_SOCK_TCP);
            $this->prepareHttp();

            $this->ws->start();
        }

        /**
         * Передналаштування вебсокет сервера
         */
        public function prepareWs():void
        {
            $this->ws->on('open', function ($server, $request) {
                echo "Connection open: {$request->fd}\n";

                $channels = $request->get['channel'] ?? [];

                foreach ($channels as $channel) {
                    $this->addConnectionToChannel($channel, $request->fd);
                    echo "\tSubscribe for channel: {$channel}\n";
                }
            });

            $this->ws->on('message', function ($server, $frame) {
                echo "Received message: {$frame->data}\n";
                $server->push($frame->fd, $frame->data);
            });

            $this->ws->on('close', function ($server, $fd) {
                echo "Connection close: {$fd}\n";

                $channels = $this->getChannels();

                foreach ($channels as $channel) {
                    $this->delConnectionFromChannelsDel($channel, $fd);
                }
            });
        }

        /**
         * Передналаштування веб сервера
         * Використовується як RTC api до вебсокетів
         */
        public function prepareHttp():void
        {
            $this->http->set(['open_http_protocol' => true]);

            $this->http->on('request', function ($request, $response) {
                
                $token = getenv('HTTP_TOKEN');
                $tokenParam = getenv('HTTP_PARAM') ?: 'token';

                $tokenRequest = @$request->get[$tokenParam];

                if ($token ?? $token !== $tokenRequest) {
                    $this->responseFalse($response, $request, 'access deny');
                } else {
                    $action = @$request->get['action'];

                    if (!$action) {
                        $this->responseFalse($response, $request, 'action param must be set');
                    } else {
                        switch ($action) {
                            case 'stats':
                                $this->responseTrue($response, $request, [
                                    'status' => true,
                                    'channels' => $this->getChannels(),
                                    'cache_info' => $this->getCacheInfo(),
                                    'ws_server' => $this->ws->stats(),
                                ]);
                                break;
                            case 'close':
                                $to = @$request->get['to'];

                                switch ($to) {
                                    case 'connection':
                                        $connection = @$request->get['connection'];

                                        if ($this->ws->close($connection)) {
                                            $this->responseTrue($response, $request);
                                        } else {
                                            $this->responseFalse($response, $request);
                                        }
                                        break;
                                    case 'channel':
                                        $channel = @$request->get['channel'];

                                        if ($channel) {
                                            $connections = $this->getChannelConnections($channel);
                    
                                            foreach ($connections as $connection) {
                                                $this->ws->close($connection);
                                            }
    
                                            $this->responseTrue($response, $request);
                                        } else {
                                            $this->responseFalse($response, $request, 'need channel param for this action');
                                        }
                
                                        break;
                                }
        
                                break;
                            case 'push':
                                $message = @$request->get['message'];
                                $to = @$request->get['to'];
        
                                switch ($to) {
                                    case 'connection':
                                        $connection = $request->get['connection'];

                                        if ($this->ws->push($connection, $message)) {
                                            $this->responseTrue($response, $request);
                                        } else {
                                            $this->responseFalse($response, $request);
                                        }
                                        break;
                                    case 'channel':
                                        $channel = @$request->get['channel'];
                                        $connections = $this->getChannelConnections($channel);

                                        foreach ($connections as $connection) {
                                            if ($this->ws->push($connection, $message)) {
                                                $this->responseTrue($response, $request);
                                            } else {
                                                $this->responseFalse($response, $request);
                                            }
                                        }
                                        break;
                                }
                                break;
                            case 'getConnections':
                                $connectionList = [];
        
                                $channel = @$request->get['channel'];
        
                                if ($channel) {
                                    $connections = $this->getChannelConnections($channel);
                                } else {
                                    $connections = $this->ws->connections;
                                }
        
                                foreach ($connections as $connection) {
                                    $connectionList[] = $connection;
                                }
        
                                $this->responseTrue($response, $request, ['connections' => $connectionList,]);
        
                                break;
                            case 'getChannels':
                                $channels = [];
        
                                /*
                                 * Якщо хочемо отримати з'єднання по одному каналу
                                 */
                                if ($channel = @$request->get['channel']) {
                                    $channels[$channel] = $this->getChannelConnections($channel);
                                } else {
                                    foreach ($this->getChannels() as $channel) {
                                        $channels[$channel] = $this->getChannelConnections($channel);
                                    }
                                }
        
                                $this->responseTrue($response, $request, ['channels' => $channels,]);
                                break;
                            default:
                                $this->responseFalse($response, $request, "wrong {$action} action");
                                break;
                        }
                    }
                }
            });
        }

        /**
         * Повертає всі з'єднання визначеного каналу
         *
         * @param $channel
         * @return array
         */
        public function getChannelConnections($channel):array
        {
            return apcu_fetch($channel) ?: [];
        }

        /**
         * Додає визначеному каналу нове з'єднання
         *
         * @param $channel
         * @param $connection
         */
        public function addConnectionToChannel($channel, $connection):void
        {
            $connections = $this->getChannelConnections($channel);

            if (!in_array($connection, $connections, false)) {
                $connections[] = $connection;
            }

            $this->setConnectionsToChannel($channel, $connections);
        }

        /**
         * Видаляє з визначеного каналу вказане з'єднання
         *
         * @param $channel
         * @param $connect
         */
        public function delConnectionFromChannelsDel($channel, $connect):void
        {
            $connections = $this->getChannelConnections($channel);

            $index = array_search($connect, $connections, false);

            if ($index !== false) {
                unset($connections[$index]);
            }
            
            /*
             * Якщо обірвалося останнє з'єднання в визначеному каналі
             * Видаляємо канал з кешу, нічого тримати його там
             */
            if ($connections) {
                $this->setConnectionsToChannel($channel, $connections);
            } else {
                apcu_delete($channel);
            }

            echo "Видалено з'єднання: {$connect} з індексом {$index} з каналу {$channel}\n";
        }

        /**
         * Перевизначає всі з'єднання визначеному каналу
         *
         * @param $channel
         * @param $connections
         */
        public function setConnectionsToChannel($channel, array $connections):void
        {
            apcu_store($channel, array_values($connections));
        }

        /**
         * Повертає всі активні канали та їх з'єднання
         *
         * @return array
         */
        public function getChannels():array
        {
            $cacheInfo = $this->getCacheInfo();

            $channels = [];

            foreach ($cacheInfo['cache_list'] as $channelData) {
                $channels[] = $channelData['info'];
            }

            return $channels;
        }

        /**
         * Отримує всю поточну інформацює про apcu кеш
         * Зазвичай в ній нам потрібно тільки перелік активних каналів
         *
         * @return array|bool
         */
        public function getCacheInfo()
        {
            return apcu_cache_info();
        }

        public function response($response, $request, $result):void
        {
            $result['request'] = $request->get ?? [];
            
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }

        public function responseFalse($response, $request, $message=''):void
        {
            $result['status'] = false;

            if ($message) {
                $result['message'] = $message;
            }

            $this->response($response, $request, $result);
        }

        public function responseTrue($response, $request, $data=[]):void
        {
            $result['status'] = true;
    
            if ($data) {
                $result['response'] = $data;
            }

            $this->response($response, $request, $result);
        }
    }

    new WebServer();