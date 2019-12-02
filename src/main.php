<?php

use Slim\Http\Request;
use Slim\Http\Response;

// Main Route

// home
$app->redirect('/', '/login');
// Auth User
$app->get('/login', function(Request $request, Response $response, $args) {
    if ($this->user) {
        return $response->withRedirect('/logger');
    }

    return $this->view->render($response, 'main/login.html');
});
$app->post('/login', function(Request $request, Response $response, $args) {
    if ($this->user) {
        return $response->withRedirect('/logger');
    }

    $credentials = $request->getParams();
    if (empty($credentials['username']) || empty($credentials['password'])) {
        $this->flash->addMessage('errors', 'Masukkan username dan password');
        return $response->withRedirect('/login');
    }

    $stmt = $this->db->prepare("SELECT * FROM users WHERE username=:username");
    $stmt->execute([':username' => $credentials['username']]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($credentials['password'], $user['password'])) {
        $this->flash->addMessage('errors', 'Username / password salah');
        return $response->withRedirect('/login');
    }

    $this->session->user_id = $user['id'];
    $this->session->user_refresh_time = strtotime("+1hour");

    $this->flash->addMessage('messages', 'Berhasil Login');
    return $this->response->withRedirect('/logger');
});

// generate admin, warning!
// $app->get('/gen', function(Request $request, Response $response, $args) {
//     $credentials = $request->getParams();
//     if (empty($credentials['username']) || empty($credentials['password'])) {
//         die("Masukkan username dan password");
//     }

//     $stmt = $this->db->prepare("SELECT * FROM public.user WHERE username=:username");
//     $stmt->execute([':username' => $credentials['username']]);
//     $user = $stmt->fetch();

//     // jika belum ada di DB, tambahkan
//     if (!$user) {
//         $stmt = $this->db->prepare("INSERT INTO public.user (username, password, role) VALUES (:username, :password, 1)");
//         $stmt->execute([
//             ':username' => $credentials['username'],
//             ':password' => password_hash($credentials['password'], PASSWORD_DEFAULT)
//         ]);
//         die("Username {$credentials['username']} ditambahkan!");
//     } else { // else update password
//         $stmt = $this->db->prepare("UPDATE public.user SET password=:password WHERE id=:id");
//         $stmt->execute([
//             ':password' => password_hash($credentials['password'], PASSWORD_DEFAULT),
//             ':id' => $user['id']
//         ]);
//         die("Password {$user['username']} diubah!");
//     }
// });

$app->post('/logout', function(Request $request, Response $response, $args) {
    $this->flash->addMessage('messages', 'Berhasil Logout');
    $this->session->destroy();
    return $this->response->withRedirect('/');
});
