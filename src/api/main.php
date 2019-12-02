<?php

use Slim\Http\Request;
use Slim\Http\Response;

// API main

$app->group('/', function() {

    $this->get('[/]', function(Request $request, Response $response, $args) {
        return $response->withJson([
          'endpoint' => "Main API"
        ]);
    });
});
