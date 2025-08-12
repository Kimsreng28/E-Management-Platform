<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Test Login with google</title>
</head>

<body>
    <h1>Test Login with google</h1>
    <a href="{{ route('auth.google.redirect') }}">Login with google</a>

    <h1>Test Login with telegram</h1>
    <a href="{{ route('auth.telegram.redirect') }}">Login with Telegram</a>
</body>

</html>
