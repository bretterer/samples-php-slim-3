<?php
// Routes

use Symfony\Component\HttpFoundation\Cookie;

$app->get('/', function ($request, $response, $args) {
    $test = true;
    return $this->view->render($response, 'overview.mustache', $args);
});


$app->get('/authorization-code/login-redirect', function ($request, $response, $args ) {
    $args =  ['config' => json_decode(file_get_contents(__DIR__ . '/../../.samples.config.json'))->oktaSample];
    return $this->view->render($response, 'login-redirect.mustache', $args );
});

$app->get('/authorization-code/login-custom', function ($request, $response, $args ) {
    $args =  ['config' => json_decode(file_get_contents(__DIR__ . '/../../.samples.config.json'))->oktaSample];
    return $this->view->render($response, 'login-custom.mustache', $args );
});

$app->get('/authorization-code/callback', function (\Slim\Http\Request $request, \Slim\Http\Response $response, $args ) {
    $config = json_decode(file_get_contents(__DIR__ . '/../../.samples.config.json'))->oktaSample;
	$cachedJwks = [];

    if (! $request->getCookieParam('okta-oauth-nonce') || ! $request->getCookieParam('okta-oauth-state') ) {
        return $response->withStatus(401);
    }

    if ( $request->getCookieParam('okta-oauth-state') != $request->getQueryParam('state')) {
        return $response->withStatus(401);
    }

    if ( ! $request->getQueryParam('code') ) {
        return $response->withStatus(401);
    }

	$authHeaderSecret = base64_encode($config->oidc->clientId . ':' . $config->oidc->clientSecret);

    $query = http_build_query([
    	'grant_type' => 'authorization_code',
	    'code' => $request->getQueryParam('code'),
	    'redirect_uri' => $config->oidc->redirectUri
    ]);

    $headers = [
	    'Authorization: Basic ' . $authHeaderSecret,
	    'Accept: application/json',
	    'Content-Type: application/x-www-form-urlencoded',
	    'Connection: close',
        'Content-Length: 0'
    ];

    $url = $config->oidc->oktaUrl . '/oauth2/v1/token?' . $query;

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_POST, 1);


	$output = curl_exec($ch);
	$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	if(curl_error($ch)) {
		$httpcode = 500;
	}

	$decodedOutput = json_decode($output);

    curl_close($ch);

	try {
		$tokenParts = explode('.',$decodedOutput->id_token);
		if (3 !== count($tokenParts) || ( 3 === count($tokenParts) && $tokenParts[2] == '' ) ) {
			return $response->withStatus(401);
		}

		$decoded_id_token = JOSE_JWT::decode($decodedOutput->id_token);

		$kid = $decoded_id_token->header['kid'];

		$jwk = null;

		if(file_exists(__DIR__ . '/../../src/storage/cache/' . $kid)) {
			$jwk = file_get_contents(__DIR__ . '/../../src/storage/cache/' . $kid);
			$jwk = unserialize($jwk);
		}
		else {

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $config->oidc->oktaUrl . '/oauth2/v1/keys');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_HEADER, 0);

			$output = curl_exec($ch);

			curl_close($ch);

			$output = json_decode($output);

			foreach ($output->keys as $key) {
				// poormans cache
				$s = serialize($key);
				file_put_contents(__DIR__ . '/../../src/storage/cache/' . $key->kid, $s);

				$cachedJwks[$key->kid] = $key;
				if ($key->kid == $kid) {
					$jwk = $key;
				}
			}
		}

		if( null === $jwk ) {
			return $response->withStatus(401);
		}

		try {
			$key = JOSE_JWK::decode((array)$jwk);

			$jws = JOSE_JWT::decode($decoded_id_token);

			if($jws->header['alg'] != $jwk->alg) {
				return $response->withStatus(401);
			}
			$res = $jws->verify($key, $jwk->alg);

			if($res->claims['nonce'] != $request->getCookieParam('okta-oauth-nonce')) {
				return $response->withStatus(401);
			}

			if($res->claims['iss'] != $config->oidc->oktaUrl) {
				return $response->withStatus(401);
			}

			if($res->claims['aud'] != $config->oidc->clientId) {
				return $response->withStatus(401);
			}

			if($res->claims['exp'] < time()-300) {
				return $response->withStatus(401);
			}

			if($res->claims['iat'] > time()+300) {
				return $response->withStatus(401);
			}

		} catch (\Exception $e) {
			return $response->withStatus(401);
		}


	} catch (\Gamegos\JWS\Exception\MalformedSignatureException $e) {
		return $response->withStatus(401);
	} catch (\Gamegos\JWS\Exception\InvalidSignatureException $e) {
		return $response->withStatus(401);
	} catch (\Exception $e) {
		return $response->withStatus(500);
	}

    $userData = [
        'email' => $res->claims['email'],
        'claims' => $res->claims
    ];


    $userCookie = new Cookie('userData', serialize($userData), time() + 30, '/', 'localhost', false, false);

    return $response->withAddedHeader('Set-Cookie', $userCookie)->withRedirect('/authorization-code/profile');



});


$app->get('/authorization-code/profile', function (\Slim\Http\Request $request, \Slim\Http\Response $response, $args ) {
    if(!isset($_COOKIE['userData'])) {
        return $response->withRedirect('/');
    }

    $userInfo = unserialize($_COOKIE['userData']);
    $args = [
        'user' => [
            'email' => $userInfo['email'],
            'claims' => $userInfo['claims']
        ],
        'config' => json_decode(file_get_contents(__DIR__ . '/../../.samples.config.json'))->oktaSample
    ];
    return $this->view->render($response, 'profile.mustache', $args );
});

$app->get('/authorization-code/logout', function ($request, \Slim\Http\Response $response, $args) {
    $userCookie = new Cookie('userData', 'EXPIRED', 1, '/', 'localhost', false, false);

    return $response->withAddedHeader('Set-Cookie', $userCookie)->withRedirect('/');

});
