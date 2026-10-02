<?php
const DB_HOST = 'localhost';
const DB_NAME = 'airline_ticket_booking';
const DB_USER = 'root';
const DB_PASS = '';

try {
	$pdo = new PDO(
		'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
		DB_USER,
		DB_PASS,
		[
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]
	);
} catch (PDOException $e) {
	http_response_code(500);
	exit('Could not connect to the database.');
}
