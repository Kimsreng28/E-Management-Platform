<?php

namespace App\Socialite;

use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

class TelegramProvider extends AbstractProvider
{
    protected $bot;
    protected $botId;

    public function __construct($request, $clientId, $clientSecret, $redirectUrl, array $guzzle = [])
    {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, $guzzle);
        $this->bot = $this->getConfig('bot');
        $this->botId = $clientId; // Use the numeric bot ID here
    }

    protected function getAuthUrl($state)
    {
        $origin = $this->request->getScheme() . '://' . $this->request->getHttpHost();

        return 'https://oauth.telegram.org/auth?' . http_build_query([
            'bot_id' => $this->botId,
            'origin' => $origin,
            'embed' => '0',
            'request_access' => 'write',
            'return_to' => $this->redirectUrl,
        ]);
    }


    protected function getTokenUrl()
    {
        return '';
    }

    protected function getUserByToken($token)
    {
        return [];
    }

    protected function mapUserToObject(array $user)
    {
        return (new User())->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['username'] ?? null,
            'name' => $user['first_name'] ?? null,
            'avatar' => $user['photo_url'] ?? null,
        ]);
    }
}