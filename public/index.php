<?php

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Flash\Messages;
use Slim\Views\PhpRenderer;
use Bosun\PhpProject9\Connect;
use GuzzleHttp\Client;
use Valitron\Validator;
use Carbon\Carbon;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use DiDom\Document;

session_start();

// Путь который будет использован при глобальной установке пакета
$autoloadPath1 = __DIR__ . '/../../../autoload.php';
// Путь для локальной работы с проектом
$autoloadPath2 = __DIR__ . '/../vendor/autoload.php';

if (file_exists($autoloadPath1)) {
    require_once $autoloadPath1;
} else {
    require_once $autoloadPath2;
}

$container = new Container();

$app = AppFactory::createFromContainer($container);

$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($container) {
    $routeContext = RouteContext::fromRequest($request);
    $route = $routeContext->getRoute();

    $container->set('routeName', $routeName = !empty($route) ? $route->getName() : '');

    return $handler->handle($request);
});

$container->set('flash', function () {
    return new Messages();
});

$container->set('router', $app->getRouteCollector()->getRouteParser());

$container->set('renderer', function () use ($container) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    $renderer->addAttribute('router', $container->get('router'));
    $renderer->addAttribute('flash', $container->get('flash')->getMessages());
    $renderer->addAttribute('routeName', $container->get('routeName'));
    return $renderer;
});

$container->set('pdo', function () {
    return (new Connect())->getConnection();
});

$container->set('client', function () {
    return new Client();
});

$app->addRoutingMiddleware();

$app->addErrorMiddleware(true, true, true);

$app->add(MethodOverrideMiddleware::class);

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
})->setName('main');

$app->post('/urls', function ($request, $response) {
    $urls = (array)$request->getParsedBody();
    $validator = new Validator($urls['url']);
    $validator
        ->rule('required', 'name')->message('URL не должен быть пустым')
        ->rule('url', 'name')->message('Некорректный URL')
        ->rule('lengthMax', 'name', 255)->message('Превышено допустимое количество символов');

    if (!($validator->validate())) {
        $errors = $validator->errors();
        $params = [
            'url' => $urls['url'],
            'errors' => $errors,
            'isInvalid' => 'is-invalid',
        ];

        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
    }


    $pdo = $this->get('pdo');
    $url = mb_strtolower($urls['url']['name']);
    $parsedUrl = parse_url($url);
    $name = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";

    $createdAt = Carbon::now();

    $query = "SELECT name FROM urls WHERE name = ?";
    $statement = $pdo->prepare($query);
    $statement->execute([$name]);
    $existedUrl = $statement->fetchAll();

    if ($existedUrl) {
        $query = "SELECT id FROM urls WHERE name = ?";
        $statement = $pdo->prepare($query);
        $statement->execute([$name]);
        $existedUrlId = $statement->fetchColumn();

        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect($this->get('router')->urlFor('show', ['id' => $existedUrlId]));
    }

    $query = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
    $statement = $pdo->prepare($query);
    $statement->execute([$name, $createdAt]);
    $lastId = $pdo->lastInsertId();

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withRedirect($this->get('router')->urlFor('show', ['id' => $lastId]));
});

$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {
    $flash = $this->get('flash')->getMessages();
    $alert = key($flash);

    if ($alert === 'error') {
        $alert = 'warning';
    }

    $id = $args['id'];
    $pdo = $this->get('pdo');
    $query = "SELECT * FROM urls WHERE id = ?";
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $currentPage = $statement->fetch();

    if ($currentPage) {
        $query = "SELECT * FROM url_checks WHERE url_id = ? ORDER BY created_at DESC";
        $statement = $pdo->prepare($query);
        $statement->execute([$id]);
        $checks = $statement->fetchAll();
        $params = [
            'flash' => $flash,
            'alert' => $alert,
            'page' => $currentPage,
            'checks' => $checks,
        ];

        return $this->get('renderer')->render($response, 'show.phtml', $params);
    }
    return $response->getBody()->write("Не удалось подключиться")->withStatus(404);
})->setName('show');

$app->post('/urls/{url_id:[0-9]+}/checks', function ($request, $response, $args) {
    $urlId = $args['url_id'];
    $pdo = $this->get('pdo');
    $query = "SELECT name FROM urls WHERE id = ?";
    $statement = $pdo->prepare($query);
    $statement->execute([$urlId]);
    $urlToCheck = $statement->fetchColumn();

    $createdAt = Carbon::now();

    $client = $this->get('client');

    try {
        $result = $client->get($urlToCheck);
        $statusCode = $result->getStatusCode();
        $this->get('flash')->addMessage('success', 'Страница успешно проверена');
    } catch (ClientException $e) {
        $statusCode = $e->getResponse()->getStatusCode();
        $result = $e->getResponse();
        $this->get('flash')->addMessage('error', 'Ошибка ' .
            $statusCode . ' при проверке страницы');
    } catch (ServerException $e) {
        $statusCode = $e->getResponse()->getStatusCode();
        $this->get('flash')->addMessage('error', 'Ошибка ' .
            $statusCode . ' при проверке страницы (внутренняя ошибка сервера)');
        return $response->withRedirect($this->get('router')->urlFor('show', ['id' => $urlId]));
    } catch (GuzzleHttp\Exception\GuzzleException) {
        $this->get('flash')->addMessage('error', 'Ошибка при проверке страницы (Connection timed out)');
        return $response->withRedirect($this->get('router')->urlFor('show', ['id' => $urlId]));
    }

    $document = new Document((string) $result->getBody());
    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->getAttribute('content');

    $query = "INSERT INTO url_checks (
        url_id,
        status_code,
        h1,
        title,
        description,
        created_at)
        VALUES (?, ?, ?, ?, ?, ?)";
    $statement = $pdo->prepare($query);
    $statement->execute([$urlId, $statusCode, $h1, $title, $description, $createdAt]);

    return $response->withRedirect($this->get('router')->urlFor('show', ['id' => $urlId]));
});

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');

    $query = 'SELECT id, name FROM urls ORDER BY id DESC';

    $urlsData = $pdo->query($query)->fetchAll();

    $queryCheck = 'SELECT
                        DISTINCT ON (url_id) url_id, created_at, status_code
                        FROM url_checks
                        ORDER BY url_id, created_at DESC;';

    $urlChecksData = $pdo->query($queryCheck)->fetchAll();

    $urlChecks = collect((array) $urlChecksData)->keyBy('url_id');
    $data = collect((array) $urlsData);

    $dataToShow = $data->map(function ($url) use ($urlChecks) {
        return array_merge($url, $urlChecks->get($url['id'], []));
    })->all();

    $params = [
        'urls' => $dataToShow
    ];
    return $this->get('renderer')->render($response, "urls.phtml", $params);
})->setName('urls');

$app->run();
