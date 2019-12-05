<?php

use Slim\Http\Request;
use Slim\Http\Response;

$app->group('/logger', function () {

    $this->group('/{sn}', function() {

        $this->get('/sensor', function (Request $request, Response $response, $args) {
        	$sn = $args['sn'];
	        $stmt = $this->db->prepare("SELECT * FROM logger WHERE sn=:sn");
	        $stmt->execute([':sn' => $sn]);
	        $logger = $stmt->fetch();

	        if (!$logger) {
	            return $response->withJson([
	                'status' => 404,
	                'message' => 'Not Found'
	            ]);
	        }

	        $url = "https://prinus.net/api/sensor/{$sn}";
	        $method = "GET";
	        $user_token = $this->session->user_basic_auth;
	        $headers = [
	            "Authorization: Basic {$user_token}"
	        ];

	        // $from = date('Y-m-d', strtotime('-1 day')) .' 23:00:00';
	        // $to   = date('Y-m-d') .' 00:00:00';
	        $from = date('Y-m-d') .' 00:00:00';
	        $to   = date('Y-m-d') .' 01:00:00';
	        $cursor = 0;

	        $target_num = 12;

	        $result = json_decode(curl($url, $method, $headers));
	        $labels = [0];
	        $data = [0 => 0];
	        $targets = [0 => $target_num];
	        $raw = [0 => []];
	        $total_data = 0;
	        $invalids = [];
	        foreach ($result as $res) {
	            $sampling = strtotime($res->sampling);
	            $time_set_at = strtotime($res->time_set_at);

	            // check if valid sampling
	            if ($sampling - $time_set_at < 1 * 60) {
	                $invalids[] = $res;
	                continue;
	            }

	            $sampling = date('Y-m-d H:i:s', $sampling);
	            if ($sampling >= $to) {
	                do {
	                    $cursor++;
	                    $labels[] = $cursor;
	                    $data[$cursor] = 0;
	                    $targets[$cursor] = $target_num;
	                    $raw[$cursor] = [];

	                    $from = date('Y-m-d H:i:s', strtotime("$from +1hour"));
	                    $to   = date('Y-m-d H:i:s', strtotime("$to +1hour"));
	                } while ($sampling > $to);
	            }

	            $data[$cursor]++;
	            // if ($data[$cursor] > $target_num) {
	            //     $data[$cursor] = $target_num;
	            // }
	            $raw[$cursor][] = $res;

	            $total_data++;

	            // var_dump($sampling);
	            // die();

	            if ($cursor > 23) { break; }
	        }

	        if ($cursor < 23)
	        {
	            do {
	                $cursor++;
	                $labels[] = $cursor;
	                $data[$cursor] = 0;
	                $targets[$cursor] = $target_num;
	                $raw[$cursor] = [];
	            } while ($cursor < 23);
	        }

	        return $response->withJson([
	            'labels' => $labels,
	            'data' => $data,
	            // 'targets' => $targets,
	            'total_data' => $total_data,
	            'raw' => $raw,
	            'invalids' => $invalids,
	        ]);
        });
    });
});