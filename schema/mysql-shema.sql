-- Schema for airline_ticket_booking
--
-- Single airline (PK Airline): airline details are constants in config/constants.php, so there is
-- no airlines table. One seat type, no classes. Each booking is one seat for the logged-in
-- customer, so there is no passengers table; the chosen seat is stored in bookings.seat_no.
--
-- Re-importing this file drops and recreates every table, so all existing data is lost.
-- Load schema/seed-data.sql afterwards for sample data.

SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `airline_ticket_booking` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `airline_ticket_booking`;

SET FOREIGN_KEY_CHECKS = 0;
-- airlines, passengers and seats belong to the old design; dropped so a re-import cleans them up.
DROP TABLE IF EXISTS `payments`, `passengers`, `seats`, `bookings`, `flights`, `routes`, `airlines`, `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'customer',
  `status` varchar(20) DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `routes` (
  `route_id` int(11) NOT NULL AUTO_INCREMENT,
  `origin` varchar(100) NOT NULL,
  `destination` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`route_id`),
  UNIQUE KEY `origin_destination` (`origin`, `destination`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- total_seats should be a multiple of the seats per row (SEAT_LETTERS in config/constants.php);
-- seat numbers (1A, 1B, ...) are generated from it, not stored. Seats left are not stored either:
-- they are counted from the flight's active bookings (paid, or pending with an unexpired hold).
CREATE TABLE `flights` (
  `flight_id` int(11) NOT NULL AUTO_INCREMENT,
  `route_id` int(11) NOT NULL,
  `flight_number` varchar(20) NOT NULL,
  `departure_time` datetime NOT NULL,
  `arrival_time` datetime NOT NULL,
  `total_seats` int(11) NOT NULL,
  `fare` decimal(10,2) NOT NULL,
  `status` varchar(20) DEFAULT 'scheduled',
  PRIMARY KEY (`flight_id`),
  UNIQUE KEY `flight_number` (`flight_number`),
  KEY `route_id` (`route_id`),
  CONSTRAINT `flights_ibfk_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`route_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- One row = one seat (one ticket) for the booking customer; booking_reference is the ticket number.
-- status: pending = seat held until hold_until (10 minutes) while the customer pays with Nepal Pay;
-- paid = payment done (hold_until is cleared). A pending booking whose hold_until has passed no
-- longer holds its seat and is ignored everywhere.
CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `flight_id` int(11) NOT NULL,
  `booking_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `seat_no` varchar(5) NOT NULL,
  `fare` decimal(10,2) NOT NULL,
  `status` varchar(20) NOT NULL,
  `hold_until` datetime DEFAULT NULL,
  `booking_reference` varchar(20) NOT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `booking_reference` (`booking_reference`),
  KEY `user_id` (`user_id`),
  KEY `flight_status` (`flight_id`, `status`),
  CONSTRAINT `bookings_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `bookings_ibfk_flight` FOREIGN KEY (`flight_id`) REFERENCES `flights` (`flight_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Simulated online payment through Nepal Pay. One payment per booking; it is inserted in the same
-- transaction that marks the booking paid. transaction_ref is Nepal Pay's receipt number (not the
-- ticket number). The Nepal Pay password is never stored.
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `payer_phone` varchar(15) NOT NULL,
  `transaction_ref` varchar(20) NOT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  UNIQUE KEY `transaction_ref` (`transaction_ref`),
  CONSTRAINT `payments_ibfk_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
