-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 26, 2026 at 03:32 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `harana_financial_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `council`
--

CREATE TABLE `council` (
  `council_id` int(11) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `position` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `term_start` date DEFAULT NULL,
  `term_end` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `photo` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `council`
--

INSERT INTO `council` (`council_id`, `last_name`, `first_name`, `middle_name`, `full_name`, `position`, `contact_number`, `email`, `term_start`, `term_end`, `status`, `photo`, `created_by`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'pascua', 'avril', 'vetyy', '', 'CEO/President', '09424462300', 'Estebanronald@gmail.com', '2000-02-23', '2030-03-30', 'active', NULL, 1, NULL, '2026-02-23 09:53:24', '2026-02-23 09:53:24'),
(2, 'jeromew', 'jabillo', 'grospe', '', 'CEO/President', '09303489945', 'ronesteban18@gmail.com', '2000-04-06', '2030-09-13', 'active', NULL, 1, NULL, '2026-02-23 09:54:22', '2026-02-23 09:54:22'),
(3, 'revira', 'gentry', 'noah', '', 'CFO/Treasurer', '09486373923', 'Dumapay07@gmail.com', '2000-09-05', '2010-10-07', 'active', NULL, 1, NULL, '2026-02-25 06:09:08', '2026-02-25 06:09:08'),
(4, 'revira', 'gentry', 'noah', '', 'CFO/Treasurer', '09486373923', 'Dumapay07@gmail.com', '2000-09-05', '2010-10-07', 'active', NULL, 1, NULL, '2026-02-25 06:09:13', '2026-02-25 06:09:13'),
(5, 'revira', 'gentry', 'noah', '', 'CFO/Treasurer', '09486373923', 'Dumapay07@gmail.com', '2000-09-05', '2010-10-07', 'active', NULL, 1, NULL, '2026-02-25 06:15:47', '2026-02-25 06:15:47'),
(6, 'revira', 'gentry', 'noah', '', 'CFO/Treasurer', '09486373923', 'Dumapay07@gmail.com', '2000-09-05', '2010-10-07', 'active', NULL, 1, NULL, '2026-02-25 06:52:56', '2026-02-25 06:52:56');

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `member_id` int(11) NOT NULL,
  `member_code` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `address` text NOT NULL,
  `present_address` text DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT 'San Jose',
  `province` varchar(100) DEFAULT 'Occidental Mindoro',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `contact_number` varchar(20) NOT NULL,
  `alternate_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `religion` varchar(100) DEFAULT NULL,
  `father_name` varchar(255) DEFAULT NULL,
  `mother_name` varchar(255) DEFAULT NULL,
  `spouse_name` varchar(255) DEFAULT NULL,
  `spouse_age` int(11) DEFAULT NULL,
  `child1_name` varchar(255) DEFAULT NULL,
  `child1_age` int(11) DEFAULT NULL,
  `child2_name` varchar(255) DEFAULT NULL,
  `child2_age` int(11) DEFAULT NULL,
  `child3_name` varchar(255) DEFAULT NULL,
  `child3_age` int(11) DEFAULT NULL,
  `child4_name` varchar(255) DEFAULT NULL,
  `child4_age` int(11) DEFAULT NULL,
  `ref1_name` varchar(255) DEFAULT NULL,
  `ref1_contact` varchar(100) DEFAULT NULL,
  `ref2_name` varchar(255) DEFAULT NULL,
  `ref2_contact` varchar(100) DEFAULT NULL,
  `date_joined` date NOT NULL,
  `status` enum('active','inactive','deceased') DEFAULT 'active',
  `monthly_contribution` decimal(10,2) NOT NULL,
  `chapter` varchar(100) DEFAULT NULL,
  `group_name` varchar(100) DEFAULT NULL,
  `leader` varchar(100) DEFAULT NULL,
  `coordinator` varchar(100) DEFAULT NULL,
  `chairman` varchar(100) DEFAULT NULL,
  `screening_officer` varchar(100) DEFAULT NULL,
  `screening_date` date DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `date_registered` date DEFAULT NULL,
  `beneficiary_name` varchar(100) DEFAULT NULL,
  `beneficiary_address` text DEFAULT NULL,
  `beneficiary_relation` varchar(50) DEFAULT NULL,
  `beneficiary_age` int(11) DEFAULT NULL,
  `beneficiary_contact` varchar(20) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `medical_certificate` tinyint(1) DEFAULT 0,
  `birth_certificate` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`member_id`, `member_code`, `first_name`, `last_name`, `middle_name`, `address`, `present_address`, `permanent_address`, `barangay`, `city`, `province`, `latitude`, `longitude`, `contact_number`, `alternate_number`, `email`, `birth_date`, `place_of_birth`, `age`, `gender`, `civil_status`, `religion`, `father_name`, `mother_name`, `spouse_name`, `spouse_age`, `child1_name`, `child1_age`, `child2_name`, `child2_age`, `child3_name`, `child3_age`, `child4_name`, `child4_age`, `ref1_name`, `ref1_contact`, `ref2_name`, `ref2_contact`, `date_joined`, `status`, `monthly_contribution`, `chapter`, `group_name`, `leader`, `coordinator`, `chairman`, `screening_officer`, `screening_date`, `approved_by`, `date_registered`, `beneficiary_name`, `beneficiary_address`, `beneficiary_relation`, `beneficiary_age`, `beneficiary_contact`, `profile_photo`, `created_by`, `created_at`, `updated_at`, `updated_by`, `medical_certificate`, `birth_certificate`) VALUES
(3, '3125', 'Ronald', 'Esteban', 'Austero', 'Mataas Na kahoy', NULL, NULL, 'Mataas na kahoy', 'San Jose', 'Occidental Mindoro', NULL, NULL, '09928867551', 'yun nrin', 'Estebanronald@gmail.com', '0000-00-00', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-25', 'active', 500.00, 'GUIMBA', 'GUimba Group 1', 'Jerome jabillo', 'Gentry noah rivera', 'Avril marge paskwal', 'ako po', '2004-07-19', 'me', '2026-02-13', 'Gentry Noah Rivera', NULL, 'Aso ko', NULL, '09928875516', NULL, 1, '2026-02-25 07:33:34', '2026-02-25 07:33:34', NULL, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `member_balances`
--

CREATE TABLE `member_balances` (
  `balance_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `total_paid` decimal(10,2) DEFAULT 0.00,
  `total_due` decimal(10,2) DEFAULT 0.00,
  `current_balance` decimal(10,2) DEFAULT 0.00,
  `last_payment_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `payment_uuid` varchar(36) NOT NULL,
  `member_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `payment_method` enum('cash','gcash','bank_transfer') DEFAULT 'cash',
  `gcash_reference` varchar(100) DEFAULT NULL,
  `payment_status` enum('pending','confirmed','failed') DEFAULT 'pending',
  `confirmed_by` int(11) DEFAULT NULL,
  `confirmed_date` timestamp NULL DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_users`
--

CREATE TABLE `pending_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role_requested` enum('admin','treasurer','viewer') DEFAULT 'viewer',
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`log_id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 13:45:22'),
(2, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 13:45:53'),
(3, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 13:47:07'),
(4, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 13:58:13'),
(5, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 13:58:45'),
(6, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:00:22'),
(7, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:08:00'),
(8, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:12:15'),
(9, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:37:15'),
(10, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:53:38'),
(11, 1, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:53:59'),
(12, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 14:54:16'),
(13, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 15:28:32'),
(14, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 15:29:03'),
(15, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '2026-02-16 15:29:57'),
(16, 1, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 15:50:49'),
(17, 1, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 15:54:50'),
(18, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 15:55:05'),
(19, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 16:07:04'),
(20, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 16:21:36'),
(21, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 16:28:30'),
(22, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:20:04'),
(23, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:20:54'),
(24, NULL, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:21:35'),
(25, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:21:53'),
(26, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:52:56'),
(27, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 06:58:01'),
(28, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 07:35:57'),
(29, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 07:40:30'),
(30, 1, 'USER_APPROVED', 'Approved user: Ronald', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 08:01:27'),
(31, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 08:01:37'),
(32, 2, 'LOGIN_SUCCESS', 'User Ronald logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 08:01:49'),
(33, 2, 'MEMBER_ADD', 'Added member: 12345', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 08:06:54'),
(34, 2, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 09:39:19'),
(35, NULL, 'LOGIN_FAILED', 'Invalid username: Gentry', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 09:40:05'),
(36, NULL, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 09:40:57'),
(37, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 09:41:11'),
(38, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-19 10:53:32'),
(39, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 05:36:49'),
(40, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 07:54:32'),
(41, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 08:11:45'),
(42, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 08:28:56'),
(43, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:06:10'),
(44, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:07:26'),
(45, 1, 'COUNCIL_ADD', 'Added council member: pascua, avril', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:53:24'),
(46, 1, 'COUNCIL_ADD', 'Added council member: jeromew, jabillo', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 09:54:22'),
(47, 1, 'USER_APPROVED', 'Approved user: Gentry', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 10:22:56'),
(48, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 10:44:54'),
(49, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 10:47:52'),
(50, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 11:28:17'),
(51, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 07:32:05'),
(52, NULL, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 11:12:30'),
(53, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 11:12:41'),
(54, 1, 'MEMBER_ADD', 'Added member: 4556', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 12:41:11'),
(55, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 12:50:12'),
(56, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 13:02:26'),
(57, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 13:03:24'),
(58, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 23:59:05'),
(59, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 05:52:11'),
(60, 1, 'COUNCIL_ADD', 'Added council member: revira, gentry', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 06:09:08'),
(61, 1, 'COUNCIL_ADD', 'Added council member: revira, gentry', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 06:09:13'),
(62, 1, 'COUNCIL_ADD', 'Added council member: revira, gentry', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 06:15:47'),
(63, 1, 'COUNCIL_ADD', 'Added council member: ', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 06:52:56'),
(64, 1, 'MEMBER_DELETE', 'Deleted member ID: 2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 07:30:46'),
(65, 1, 'MEMBER_DELETE', 'Deleted member ID: 1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 07:30:48'),
(66, 1, 'MEMBER_ADD', 'Added member: 3125', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 07:33:34'),
(67, NULL, 'LOGIN_FAILED', 'Invalid username: Avril', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 08:41:44'),
(68, NULL, 'LOGIN_FAILED', 'Wrong password for user: admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 08:58:13'),
(69, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 08:58:25'),
(70, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:05:59'),
(71, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:15:56'),
(72, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:36:38'),
(73, NULL, 'LOGIN_FAILED', 'Wrong password for user: Admin', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:36:53'),
(74, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:37:06'),
(75, 1, 'LOGOUT', 'User logged out', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 09:37:32'),
(76, NULL, 'LOGIN_FAILED', 'Invalid username: admin &#039; OR &#039;1&#039;=&#039;1', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 16:39:21'),
(77, 1, 'LOGIN_SUCCESS', 'User admin logged in', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-26 02:24:22');

-- --------------------------------------------------------

--
-- Table structure for table `two_factor_backup_codes`
--

CREATE TABLE `two_factor_backup_codes` (
  `code_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `backup_code` varchar(255) NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `two_factor_backup_codes`
--

INSERT INTO `two_factor_backup_codes` (`code_id`, `user_id`, `backup_code`, `is_used`, `created_at`) VALUES
(1, 1, '$2y$12$eye5eZ41a9I0cvaw5wCFOeCHTLYGLTms.PbDQFSq4r9ei3zsE/Qiu', 0, '2026-02-16 15:41:45'),
(2, 1, '$2y$12$RNNmbhtmxnr1Py.DCbEpoO8XKJ0udUFinxuwWvV3wG8zsERwOz13a', 0, '2026-02-16 15:41:45'),
(3, 1, '$2y$12$Cc7/Q5hA4BDahVdT1hsaBebRqJvYn1rjbZkCePoMjHpXX1xGcGPmi', 0, '2026-02-16 15:41:46'),
(4, 1, '$2y$12$aJil9soYUMpOh1x2wmbu5ODAZC5/7aGFrLtJPAVJH6fGl7xUrEJfq', 0, '2026-02-16 15:41:47'),
(5, 1, '$2y$12$1bgvBKgPtdFz06wzj63gBuRoUprh3IUkwX5yynmegRxl1BKbvA/EW', 0, '2026-02-16 15:41:48'),
(6, 1, '$2y$12$DkpBMTf5sLWwTnDVSVA9meQaD0mO9/8bKqr9/G4FGzAktCupu2MWS', 0, '2026-02-16 15:41:49'),
(7, 1, '$2y$12$XrPVoApLgIJ6NwtEQJULTuC5O8COiCXTtLCLMKeYb4W3Qbsn8jJKW', 0, '2026-02-16 15:41:50'),
(8, 1, '$2y$12$Toep9tLBpiv.h08G/6rJZOvuFq5DppiQ66I1wxNypZBdNFE67u/iu', 0, '2026-02-16 15:41:51'),
(9, 1, '$2y$12$4TMXZdjwOIiMr67u5U40QOthNTbEtzwgaz830JuftuJyy.xAiT7qq', 0, '2026-02-16 16:17:40'),
(10, 1, '$2y$12$ZLsRvBMd36bwaEIyx4ocCenHDghKckzyofoR7/zUyQ8wPpeLMDS3C', 0, '2026-02-16 16:17:41'),
(11, 1, '$2y$12$9t0XAM3kJd8KGmSaWVxgruWa1QM0ew/kmYNO.lqOha/OJGqyQEDx.', 0, '2026-02-16 16:17:42'),
(12, 1, '$2y$12$77dxGzQM.gFmaHoyHbfPH.v2mryE1wG/Adm/1sw7L4JuArVvlhuzC', 0, '2026-02-16 16:17:43'),
(13, 1, '$2y$12$yzyiu9S66a5LBQTVAVGMR.IWQA7DDle8y3f1qsRCoC/o1/S1mWrTG', 0, '2026-02-16 16:17:44'),
(14, 1, '$2y$12$9sRtM.LsJNKo/TeUQuSBUOmID9u.EnZCHamxm3MtOE7HrW1UWc41O', 0, '2026-02-16 16:17:45'),
(15, 1, '$2y$12$D4lFA1VSRBvzdzRMgbGNAeD8F8WGj.kaqch7bfteIw6Hsvv./pWfq', 0, '2026-02-16 16:17:46'),
(16, 1, '$2y$12$rNUn/.Jl3EJ14OUXy4y8nehENO.MfiKOnqOiQT3KR2t2UnepPD5sy', 0, '2026-02-16 16:17:47');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','treasurer','viewer') DEFAULT 'viewer',
  `is_active` tinyint(1) DEFAULT 1,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `login_attempts` int(11) DEFAULT 0,
  `last_login_attempt` timestamp NULL DEFAULT NULL,
  `locked_until` timestamp NULL DEFAULT NULL,
  `password_reset_token` varchar(100) DEFAULT NULL,
  `password_reset_expires` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `last_ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `full_name`, `email`, `role`, `is_active`, `two_factor_secret`, `two_factor_enabled`, `login_attempts`, `last_login_attempt`, `locked_until`, `password_reset_token`, `password_reset_expires`, `created_at`, `last_login`, `last_ip_address`) VALUES
(1, 'admin', '$2y$12$K6SKiPBDnyK1GpIkYQfa7e9dF/UdPbKFF0N4LJsdRo99yb5yZe.2G', 'System Administrator', 'admin@harana.com', 'admin', 1, '0d11d67f398fe95b239af692094cd422e7ec2513', 0, 0, '2026-02-25 09:36:53', NULL, NULL, NULL, '2026-02-16 13:42:29', '2026-02-26 02:24:22', '::1'),
(2, 'Ronald', '$2y$12$ZbudJaZM2NHNNnzBAeNnDOB/GE5WvXznYS1LSgUJxyT73SBn9hNcC', 'Ronald A. Esteban', 'Estebanronald22.0069@gmail.com', 'admin', 1, NULL, 0, 0, NULL, NULL, NULL, NULL, '2026-02-17 08:01:27', '2026-02-17 08:01:49', '::1'),
(3, 'Gentry', '$2y$12$6j2.6DkX3wgPxZETtIog/OpLgq/EqAQn81j75CzSx00kLe/Degyi2', 'Gentry Noah Rivera', 'Gentry@gmail.com', 'viewer', 1, NULL, 0, 0, NULL, NULL, NULL, NULL, '2026-02-23 10:22:56', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`session_id`, `user_id`, `session_token`, `ip_address`, `user_agent`, `expires_at`, `created_at`) VALUES
(1, 1, 'd45f7fd7fbaf4a1a2bb83edd92469fc47e7a9c56dc68330affe0403192a6b72a', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 08:45:53', '2026-02-16 13:45:53'),
(2, 1, '7bb8a232e6096f10282e258ba62762d5c572d368594f2680cffd4ab85a7db543', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 08:47:07', '2026-02-16 13:47:07'),
(3, 1, '4eb8e978cd16058ad4d2df3c7efe30eebd3f66fbb9c68193571835835c7b1164', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 08:58:13', '2026-02-16 13:58:13'),
(4, 1, 'fb2fdf54b43642bfc9d541b7efb8bf913e64bc3a60aaff42a36f412aa72d0fe2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 08:58:45', '2026-02-16 13:58:45'),
(5, 1, 'f8928b4f962dd13e1247ab2001ac94c144ef1ff371fb28d2440fae3fcc12038d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:00:22', '2026-02-16 14:00:22'),
(6, 1, 'edc50c80b703141f01b4a4df38cde1b09080941600af30f8397046178ac21d25', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:08:00', '2026-02-16 14:08:00'),
(7, 1, 'f85d2613ce9fc8c73f81098086cfd79749d1c18333a00365ec0ff2744de7858a', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:12:15', '2026-02-16 14:12:15'),
(8, 1, '759a3639731dc8e6bbe9b4282845ed737ae59337a8754801122a50c8586be080', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:37:15', '2026-02-16 14:37:15'),
(9, 1, 'c293d9a8f6341085b6d4afe41c94446dd49b5fba965444d701fb95355010536f', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:53:38', '2026-02-16 14:53:38'),
(10, 1, '4db550677eea05d9af6bca2e5be60ba1a51db919191d6e14fabf799510197224', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 09:54:16', '2026-02-16 14:54:16'),
(11, 1, 'b4bf8af9fde8c31cb79b394e673ec6d71855ac2ab115d8ef5fe87a011fda7d73', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 10:28:32', '2026-02-16 15:28:32'),
(12, 1, 'bcf0b28d1dc1e4d5f31c954b18296697413a54b42e5f0de09e92695ae4f0cb25', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 10:29:03', '2026-02-16 15:29:03'),
(13, 1, '22361424ff3ba2246f4d4d9a462c081a3ba86be0818d44daabb5d8d7c1e021c1', '::1', 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36', '2026-02-16 10:29:57', '2026-02-16 15:29:57'),
(14, 1, '2351a8f02e2f33772629c9f5d6b6eae82f167d2c2064a3d00e270862e7d0d3b7', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 10:55:05', '2026-02-16 15:55:05'),
(16, 1, '158f10540c47118cd684dc60f463392eb091c2eac6c2ce94721de106888acf0e', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-16 11:28:30', '2026-02-16 16:28:30'),
(22, 1, 'bd6e8e05e05b0ad753b069b390f29273fd448be045dd11ac7e4e28403e679d9f', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-17 04:41:11', '2026-02-17 09:41:11'),
(23, 1, '3d6204ea200672316d78f52aa2543e7a6c72e5040b452dfb3e7c2e305a51873c', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-19 05:53:32', '2026-02-19 10:53:32'),
(24, 1, 'd47ba71be62a8db7880b5ea742b958f68c00f9fb300fa27a2a1ece22121d66e6', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-23 00:36:49', '2026-02-23 05:36:49'),
(30, 1, '1f2cf5feb30e9dc8f1eba238d5864094b39c0a9271fe3bad2b75ceddacacf2ea', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 06:12:41', '2026-02-24 11:12:41'),
(32, 1, '542e49682cbbed426b9122bd39f7648643878d9f7b0e9da033a9793d185bb21d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-24 18:59:05', '2026-02-24 23:59:05'),
(33, 1, '3ec76bd7356b416e4f9fcb5d9ba259a5b7bf74f886b8b61002fb957229541f5b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 00:52:11', '2026-02-25 05:52:11'),
(37, 1, 'e45d9c5debb343a30e27ada446f65972c58594f5cefe7af266ca3c17db0ce3b2', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '2026-02-25 21:24:22', '2026-02-26 02:24:22');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `council`
--
ALTER TABLE `council`
  ADD PRIMARY KEY (`council_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`member_id`),
  ADD UNIQUE KEY `member_code` (`member_code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `member_balances`
--
ALTER TABLE `member_balances`
  ADD PRIMARY KEY (`balance_id`),
  ADD UNIQUE KEY `member_id` (`member_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `payment_uuid` (`payment_uuid`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `confirmed_by` (`confirmed_by`);

--
-- Indexes for table `pending_users`
--
ALTER TABLE `pending_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `two_factor_backup_codes`
--
ALTER TABLE `two_factor_backup_codes`
  ADD PRIMARY KEY (`code_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `council`
--
ALTER TABLE `council`
  MODIFY `council_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `members`
--
ALTER TABLE `members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `member_balances`
--
ALTER TABLE `member_balances`
  MODIFY `balance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_users`
--
ALTER TABLE `pending_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `two_factor_backup_codes`
--
ALTER TABLE `two_factor_backup_codes`
  MODIFY `code_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `session_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `council`
--
ALTER TABLE `council`
  ADD CONSTRAINT `council_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `council_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `members`
--
ALTER TABLE `members`
  ADD CONSTRAINT `members_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `members_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `member_balances`
--
ALTER TABLE `member_balances`
  ADD CONSTRAINT `member_balances_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `two_factor_backup_codes`
--
ALTER TABLE `two_factor_backup_codes`
  ADD CONSTRAINT `two_factor_backup_codes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
