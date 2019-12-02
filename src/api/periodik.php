<?php

use Slim\Http\Request;
use Slim\Http\Response;

// API periodik

$app->group('/periodik', function() {

    $this->get('[/]', function(Request $request, Response $response, $args) {
        return $response->withJson([
          'endpoint' => "Periodik API"
        ]);
    });
});
