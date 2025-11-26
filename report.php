<?php

function isValidTimeStamp($timestamp) {
    return (
        is_int($timestamp)
        && ($timestamp <= PHP_INT_MAX)
        && ($timestamp >= 0)
    );
}

function cmp_timestamp($a, $b) {
    if ($a['timestamp'] == $b['timestamp']) {
        return 0;
    }

    //ORDER BY timestamp DESC
    return ($a['timestamp'] > $b['timestamp']) ? -1 : 1;
}




//1. Валидирует входные данные 
function validate($events) {
	$error = array(
		"status" => 400,
	    "error" => "Bad Request",
	    "message" => ""
	);

	foreach ($events as $event) {
		if (!isset($event['user_id']) || !is_numeric($event['user_id'])) {
			header( 'HTTP/1.1 400 Bad Request' );
			$error['message'] = "Error in column user_id";
			die(json_encode($error));
		}
		if (!isset($event['type']) || !in_array($event['type'], array("view", "click", "purchase"))) {
			header( 'HTTP/1.1 400 Bad Request' );
			$error['message'] = "Error in column type";
			die(json_encode($error));
		}
		if (!isset($event['timestamp']) || !isValidTimeStamp($event['timestamp'])) {
			header( 'HTTP/1.1 400 Bad Request' );
			$error['message'] = "Error in column timestamp";
			die(json_encode($error));
		}
	}
}


//2. Для каждого пользователя вычислите: 
function calculate($events) {
	$calc_users = array();

	foreach ($events as $event) {
		$calc_users[$event['user_id']]['events'][] = $event;
		/*if (isset($calc_users[$event['user_id']])) {
			// code...
		}*/
	}

	foreach ($calc_users as $user_id => &$calc_user) {
		$calc_user['user_id'] = $user_id;
		$calc_user['total_events'] = count($calc_user['events']);


		$calc_user['has_purchase'] = false;
		foreach ($calc_user['events'] as $event) {
			//to unique_days
			$calc_user['timestamps'][date("Y-m-d", $event['timestamp'])] = date("Y-m-d", $event['timestamp']);


			//to has_purchase
			if ($event['type'] == 'purchase') {
				$calc_user['has_purchase'] = true;
			}
		}
		$calc_user['unique_days'] = count($calc_user['timestamps']);
		unset($calc_user['timestamps']);


		//Есть ли последовательность "view -> click -> purchase" в течение 10 минут 
		//sorting events ORDER BY timestamp DESC
		usort($calc_user['events'], 'cmp_timestamp');

		$is_purchase = false;
		$timestamp_purchase = false;
		$is_click = false;
		//$is_view = false;


		//
		$calc_user['has_conversion_funnel'] = false;
		foreach ($calc_user['events'] as $event) {

			//to is_purchase
			if ($event['type'] == 'purchase') {
				$is_purchase = true;
				$timestamp_purchase = $event['timestamp'];
			}

			if ($is_purchase) {
				//next step click
				if ($event['type'] == 'click') {
					$is_click = true;
				}
			}

			if ($is_purchase && $is_click) {
				//next step click
				if (
					$event['type'] == 'view' &&
					$timestamp_purchase - $event['timestamp'] <= 600
				) {
					$calc_user['has_conversion_funnel'] = true;

				}
			}

		}

	}

	return $calc_users;
}

//3. Отфильтруйте пользователей: 
function filtering_users($users) {
	foreach ($users as $key =>$user) {
		if (
			!$user['has_conversion_funnel'] ||
			$user['unique_days'] < 2
		) {
			unset($users[$key]);
		}
	}

	output($users);
}

//4. Отсортируйте результат по приоритету:  
function sorting_users($users) {
	//Сначала пользователи с успешной последовательностью (в порядке убывания user_id) 
	//ORDER BY user_id DESC
	usort($users, function ($a, $b) {
	    return ($a['user_id'] > $b['user_id']) ? -1 : 1;
	} );

	$conversion_funnel_users = array();

	foreach ($users as $key => $user) {
		if ($user['has_conversion_funnel']) {
			$conversion_funnel_users[] = $user;
			unset($users[$key]);
		}
	}


	//Затем — остальные (по убыванию числа уникальных дней, затем по user_id) 
	$unique_days_users = $users;



	//ORDER BY unique_days DESC
	usort($unique_days_users, function ($a, $b) {
	    return ($a['unique_days'] > $b['unique_days']) ? -1 : 1;
	} );



	//push unique_days_users to conversion_funnel_users
	$output_users = $conversion_funnel_users;
	foreach ($unique_days_users as $user) {
		$output_users[] = $user;
	}
	

	output($output_users);
}

//output qualified_users
function output($users) {
	$output = array();

	foreach ($users as $user) {
		$output['qualified_users'][]['user_id'] = $user['user_id']; 
		$output['qualified_users'][]['total_events'] = $user['total_events']; 
		$output['qualified_users'][]['unique_days'] = $user['unique_days']; 
		$output['qualified_users'][]['has_purchase'] = $user['has_purchase']; 
		$output['qualified_users'][]['has_conversion_funnel'] = $user['has_conversion_funnel'];
	}
	echo json_encode($output);
}

$input = file_get_contents('php://input');

$input = json_decode($input, true);
$events = $input['events'];


validate($events);

$users = calculate($events);

//Противоречия 3 и 4 пунктов(Отфильтруйт, Отсортируйте)
//Реализовал два вывода

//filtering_users($users);
sorting_users($users);


