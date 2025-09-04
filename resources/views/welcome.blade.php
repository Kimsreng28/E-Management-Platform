<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Broadcast Test</title>

    <!-- Pusher client -->
    <script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>

    <!-- Laravel Echo (UMD build) -->
    <script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.15.3/dist/echo.iife.js"></script>
</head>

<body>
    <h1>Broadcast Test Page</h1>
    <div id="output">Waiting for events...</div>

    <script>
        window.Echo = new Echo({
            broadcaster: 'pusher', // Reverb uses Pusher protocol
            key: "{{ env('REVERB_APP_KEY') }}",
            wsHost: "{{ env('REVERB_HOST', '127.0.0.1') }}",
            wsPort: {{ env('REVERB_PORT', 8080) }},
            forceTLS: false,
            enabledTransports: ['ws'],
            disableStats: true, // prevents cluster requirement
            cluster: "mt1", // dummy cluster, needed to satisfy pusher-js
        });

        window.Echo.join(`chat.1`)
            .listen('.test.event', (e) => {
                console.log("Received event:", e);
                document.getElementById('output').innerText =
                    "Event received: " + JSON.stringify(e);
            })
            .here(users => {
                console.log("Currently in channel:", users);
            })
            .joining(user => {
                console.log("User joined:", user);
            })
            .leaving(user => {
                console.log("User left:", user);
            });
    </script>
</body>

</html>
