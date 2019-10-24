# Встановлення та використання

Простий вебсокет сервер, котрий базується на swoole та має RTC http api.

#### Запускаємо контейнер.

`docker run --rm -itd -p 8080:80 -p 9090:8080 nagard/websocket`

#### Через docker-compose

```yaml
version: '2'
services:
    websocket:
        image: nagard/websocket
        ports:
            - "8080:80"
            - "9090:8080"
```

Базовя налаштування відбуваєтьсе через задання наступних змінних оточення:
* HTTP_HOST=0.0.0.0
* HTTP_PORT=8080
* HTTP_TOKEN=
* HTTP_PARAM=token
* WS_HOST=0.0.0.0
* WS_PORT=80

Якщо задати `HTTP_TOKEN` то для використання http api необхідоно буде аторизуватися через додання до запиту `<HTTP_TOKEN>=<HTTP_PARAM>`

Для змінних оточення

HTTP_PARAM=hash
HTTP_TOKEN=12345

Запит буде наступним
```http request
http://127.0.0.1:9090/?action=stats&hash=12345
```

Тепер ваш сокет сервер працює. Для підключення використовуєте настопний код:

```html
<form name="publish">
    <input type="text" name="message">
    <input type="submit">
</form>

<div id="subscribe"></div>

<script>
    var socket = new WebSocket("ws://0.0.0.0:8080?channel[]=channel");

    socket.onopen = function () {
        console.log("З'єднання відбулося успішно.");
    };

    socket.onmessage = function (event) {
        console.log("Отримано повідомлення " + event.data);

        var messageElem = document.createElement('div');
        messageElem.appendChild(document.createTextNode(event.data));
        document.getElementById('subscribe').appendChild(messageElem);
    };

    socket.onclose = function (event) {
        if (event.wasClean) {
            console.log("З'єднання було зачинено");
        } else {
            console.log("З'єднання було обірвано");
        }
        console.log('Код: ' + event.code + ' причина: ' + event.reason);
    };

    socket.onerror = function (error) {
        console.log("Помилка " + error.message);
    };

    document.forms.publish.onsubmit = function () {
        socket.send(this.message.value);
        return false;
    };
</script>
```

При підключенні необхідно передати масив каналів (параметр `channel`), на які ви хочете підписатися.

## Робота з http api

#### Надсилання повідомлень

Надіслати повідомлення в визначений канал. Його отримають всі підписники.

```http request
http://127.0.0.1:9090/?action=push&to=channel&channel=channel&message=Повідомлення+для+слухачів+каналу+channel
```

Відповідь

```json
{
    "status": true,
    "request": {
        "action": "push",
        "to": "channel",
        "channel": "channel",
        "message": "Повідомлення для слухачів каналу channel"
    }
}
```

Надіслати повідомлення визначеному з'єднанню

```http request
http://127.0.0.1:9090/?action=push&to=connection&connection=9&message=Повідомлення+для+слухачів+каналу+channel
```

Відповідь

```json
{
    "status": true,
    "request": {
        "action": "push",
        "to": "connection",
        "connection": "9",
        "message": "Повідомлення для слухачів каналу channel"
    }
}
```

#### Отримання всіх каналів котрі на котрі хтось підписаний

`http://127.0.0.1:9090/?action=getChannels`

Відповідь, буде містити всі канали, та всі активні підключення по кожному з них

```json
{
    "status": true,
    "response": {
        "channels": {
            "channel": [
                9,
                13
            ]
        }
    },
    "request": {
        "action": "getChannels"
    }
}
```

Якщо бажаєте отримати визначений канал - використовуйте параметр `channel`

```http request
http://127.0.0.1:9090/?action=getChannels&channel=channel
```

#### Отримання всіх активних підключень

```http request
http://127.0.0.1:9090/?action=getConnections
```

Відповідь, буде містити всі активні підключення, з якими сервер тримає з'єднання

```json
{
    "status": true,
    "response": {
        "connections": [
            9,
            10,
            16,
            13
        ]
    },
    "request": {
        "action": "getConnections"
    }
}
```

Якщо бажаєте всі з'єднання по визначеному каналу - використовуйте параметр `channel`

```http request
http://127.0.0.1:9090/?action=getConnections&channel=channel
```

Відповідь

```json
{
    "status": true,
    "response": {
        "connections": [
            9,
            13
        ]
    },
    "request": {
        "action": "getConnections",
        "channel": "channel"
    }
}
```

#### Примусове закриття з'єднань

Якщо є необхідність закрити визначене з'єднання

```http request
http://127.0.0.1:9090/?action=close&to=connection&connection=9
```

Відповідь

```json
{
    "status": true,
    "request": {
        "action": "close",
        "to": "connection",
        "connection": "9"
    }
}
```

Якщо є необхідність закрити всі з'єднання визначеного каналу

```http request
http://127.0.0.1:9090/?action=close&to=channel&channel=channel
```

Відповідь

```json
{
    "status": true,
    "request": {
        "action": "close",
        "to": "channel",
        "channel": "channel"
    }
}
```

#### Отримати загальну статистику по всьому серверу

```http request
http://127.0.0.1:9090/?action=stats
```

Відповідь

```json
{
    "status": true,
    "response": {
        "status": true,
        "channels": [
            "channel"
        ],
        "cache_info": {
            "num_slots": 4099,
            "ttl": 0,
            "num_hits": 45,
            "num_misses": 4,
            "num_inserts": 27,
            "num_entries": 1,
            "expunges": 0,
            "start_time": 1571918020,
            "mem_size": 184,
            "memory_type": "mmap",
            "cache_list": [
                {
                    "info": "channel",
                    "ttl": 0,
                    "num_hits": 0,
                    "mtime": 1571918020,
                    "creation_time": 1571918020,
                    "deletion_time": 0,
                    "access_time": 1571918020,
                    "ref_count": 0,
                    "mem_size": 184
                }
            ],
            "deleted_list": [],
            "slot_distribution": {
                "1886": 1
            }
        },
        "ws_server": {
            "start_time": 1571918020,
            "connection_num": 3,
            "accept_count": 26,
            "close_count": 23,
            "worker_num": 4,
            "idle_worker_num": 3,
            "tasking_num": 0,
            "request_count": 59,
            "worker_request_count": 52,
            "worker_dispatch_count": 53,
            "coroutine_num": 1
        }
    },
    "request": {
        "action": "stats"
    }
}
```

#### Помилки

```http request
http://127.0.0.1:9090/?action=unknown
```

Відповідь

```json
{
    "status": false,
    "message": "wrong unknown action",
    "request": {
        "action": "unknown"
    }
}
```