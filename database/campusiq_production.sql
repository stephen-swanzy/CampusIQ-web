-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: campusiq
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `academic_years`
--

DROP TABLE IF EXISTS `academic_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `academic_years`
--

LOCK TABLES `academic_years` WRITE;
/*!40000 ALTER TABLE `academic_years` DISABLE KEYS */;
INSERT INTO `academic_years` VALUES (1,'2024/2025','2024-09-01','2025-07-31',0,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(2,'2025/2026','2025-09-01','2026-07-31',1,'2026-09-01 15:15:51','2026-09-01 15:15:51');
/*!40000 ALTER TABLE `academic_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `access_requests`
--

DROP TABLE IF EXISTS `access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `access_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role` enum('teacher','student','parent','accountant','school_admin') NOT NULL,
  `request_type` varchar(50) NOT NULL,
  `student_name` varchar(150) DEFAULT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_access_requests_email` (`email`),
  KEY `idx_access_requests_status` (`status`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `access_requests_ibfk_1` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `access_requests`
--

LOCK TABLES `access_requests` WRITE;
/*!40000 ALTER TABLE `access_requests` DISABLE KEYS */;
INSERT INTO `access_requests` VALUES (1,'akwesi sean paul','Akwesi@gmail.com','0204537810','student','account','akwesi sean paul','j.h.s 1','',NULL,'approved',NULL,'2026-09-01 15:24:38','2026-09-01 15:23:45','2026-09-01 15:24:38'),(2,'paul swanzy','paul@gmail.com','0204537810','teacher','teacher_account','Paul swanzy','j.h.s','',NULL,'approved',NULL,'2026-09-01 15:27:42','2026-09-01 15:26:37','2026-09-01 15:27:42'),(3,'mennsura','mensura@gmail.com','0204537810','teacher','teacher_account','issah mennsura','j.h.s 1,j.h.s 2,j.h.s 3','',NULL,'rejected',NULL,'2026-09-01 15:44:13','2026-09-01 15:42:49','2026-09-01 15:44:13'),(4,'mennsua','mennsua@gmai.com','0204537810','teacher','teacher_account',NULL,'j.h.s 2','',NULL,'approved',NULL,'2026-09-01 15:48:14','2026-09-01 15:46:02','2026-09-01 15:48:14'),(5,'dickson dogbe','dicksondogbe49@gmail.com','0257633076','teacher','teacher_account',NULL,NULL,'',NULL,'approved',14,'2026-10-07 20:38:52','2026-10-07 20:36:44','2026-10-07 20:38:52'),(8,'dickson dogbe','dicksondogbe49@gmail.com','0257633076','teacher','teacher_account',NULL,NULL,'',NULL,'approved',14,'2026-10-07 21:54:26','2026-10-07 21:52:41','2026-10-07 21:54:26'),(10,'akwesi swanzy','stephenswanzy2003@gmail.com','0204537810','student','student_portal','Akwesi swanzy','jhs 2','','$2y$10$Nv76eAldLlGuPK./w434su6yisP6grsyYodK0Wz.r7zPZpPTMzO.y','approved',14,'2026-10-10 09:16:58','2026-10-10 09:16:03','2026-10-10 09:16:58'),(11,'Saviour Semakor','savioursemakor24@gmail.com','0249242084','student','student_portal','Saviour Semakor','Jhs2','','$2y$10$lqvNwBUScFY1FzcyJgxImumJUK3Y7bYniKrYkpb2WxwZegGV26Po6','approved',14,'2026-10-10 09:33:02','2026-10-10 09:31:01','2026-10-10 09:33:02');
/*!40000 ALTER TABLE `access_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `account_activations`
--

DROP TABLE IF EXISTS `account_activations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `account_activations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`),
  KEY `idx_request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `account_activations`
--

LOCK TABLES `account_activations` WRITE;
/*!40000 ALTER TABLE `account_activations` DISABLE KEYS */;
INSERT INTO `account_activations` VALUES (3,8,'dicksondogbe49@gmail.com','ef79cab2c226ea0ea8c95639d2069f44ed01bcab7d8169bf48f7e8ad78a9c89c','2026-10-10 23:54:26',0,'2026-10-07 21:54:26'),(4,10,'stephenswanzy2003@gmail.com','acb9bf48ff4bafbf9596f9d8c32e14846f365f5ee329e847c4a28b0c50e996f0','2026-10-13 11:16:58',0,'2026-10-10 09:16:58'),(5,11,'savioursemakor24@gmail.com','76447d6de8c8710863ea9abb87ee4df859cc743626ce2e9a9dd5d4e9d5d388c2','2026-10-13 11:33:02',0,'2026-10-10 09:33:02');
/*!40000 ALTER TABLE `account_activations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(200) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_logs_user` (`user_id`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,1,'Login','Administrator logged into CampusIQ','2026-09-01 15:15:52'),(2,2,'Attendance','Attendance recorded for class JHS 1A','2026-09-01 15:15:52'),(3,4,'Fee View','Student viewed fee statement','2026-09-01 15:15:52'),(4,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 15:19:10'),(5,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 15:24:10'),(6,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 15:27:28'),(7,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 15:43:23'),(8,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 15:47:55'),(9,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 16:34:17'),(10,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 16:38:48'),(11,13,'Login','Teacher logged into CampusIQ','2026-09-01 16:43:36'),(12,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 16:44:24'),(13,13,'Login','Teacher logged into CampusIQ','2026-09-01 16:49:55'),(14,NULL,'Login','Super_admin logged into CampusIQ','2026-09-01 16:57:54'),(15,1,'Login','Super_admin logged into CampusIQ','2026-09-05 15:01:41'),(16,1,'Login','Super_admin logged into CampusIQ','2026-09-05 15:04:03'),(17,1,'Login','Super_admin logged into CampusIQ','2026-10-02 08:41:08'),(18,14,'Login','Super admin logged into CampusIQ with two-factor authentication','2026-10-02 18:16:22'),(19,14,'Login','Super_admin logged into CampusIQ with two-factor authentication','2026-10-02 18:21:12'),(20,14,'Login','Super_admin logged into CampusIQ with two-factor authentication','2026-10-02 19:04:36'),(21,14,'OTP Requested','Password reset OTP requested. Dispatch status: Fallback: SMTP credentials not configured and local mail() transport failed.','2026-10-02 19:18:03'),(22,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-02 21:19:17'),(23,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-02 21:39:28'),(24,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-02 22:33:13'),(25,1,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-02 23:23:22'),(26,1,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-03 22:27:46'),(27,1,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-03 23:35:18'),(28,1,'Login','Super_admin logged in','2026-10-07 17:31:04'),(29,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-07 17:47:17'),(30,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-07 18:41:10'),(31,1,'Login','Super_admin logged in','2026-10-07 18:48:32'),(32,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-07 20:31:24'),(33,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-07 20:37:52'),(34,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-07 21:53:52'),(35,1,'Login','Super_admin logged in','2026-10-07 23:06:12'),(36,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-07 23:09:14'),(37,1,'Login','Super_admin logged in','2026-10-07 23:40:52'),(38,1,'Login','Super_admin logged in','2026-10-08 14:15:36'),(39,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-08 14:18:47'),(40,14,'Student Added','Added student: delyte zorgadzi (STU-021)','2026-10-08 14:20:50'),(41,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-08 14:23:02'),(42,21,'Login','Student logged in','2026-10-08 14:23:44'),(43,21,'Fee Payment','Paid GH??? 100 via MTN Mobile Money (Ref: PAY-MM-62BD0A01)','2026-10-08 14:24:09'),(44,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-08 14:25:44'),(45,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-08 16:05:42'),(46,1,'Login','Super_admin logged in','2026-10-08 18:30:27'),(47,1,'Login','Super_admin logged in','2026-10-08 18:50:32'),(48,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-09 10:42:43'),(49,1,'Login','Super_admin logged in','2026-10-09 11:06:47'),(50,1,'Login','Super_admin logged in','2026-10-09 11:33:11'),(51,1,'Login','Super_admin logged in','2026-10-09 14:05:01'),(52,1,'Fee Assigned','Assigned fee of GH??? 300 to student ID #6','2026-10-09 14:23:26'),(53,1,'Profile Photo Updated','System Administrator changed their profile picture','2026-10-09 14:59:38'),(54,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-09 15:10:14'),(55,14,'Profile Photo Updated','Stephen Swanzy changed their profile picture','2026-10-09 15:11:55'),(56,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-09 16:35:50'),(57,14,'OTP Requested','Password reset OTP requested. Dispatch status: Fallback: Invalid greeting from SMTP: ','2026-10-09 16:41:04'),(58,14,'OTP Requested','Password reset OTP requested. Dispatch status: Sent','2026-10-09 16:41:09'),(59,14,'OTP Requested','Password reset OTP requested. Dispatch status: Sent','2026-10-09 16:41:12'),(60,14,'Password Reset Completed','User successfully updated password using verified OTP','2026-10-09 16:42:09'),(61,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-09 16:42:38'),(62,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-09 18:11:18'),(63,1,'Login','Super_admin logged in','2026-10-09 18:18:15'),(64,4,'Login','Student logged in','2026-10-09 22:54:49'),(65,4,'Paystack Payment Verified','Verified Paystack payment of GH??? 200.00 for student Esi Boateng (Ref: TEST-VERIFY-1791588011-4697)','2026-10-09 23:20:11'),(66,4,'Login','Student logged in','2026-10-09 23:30:32'),(67,7,'Login','Parent logged in','2026-10-09 23:32:34'),(68,1,'Login','Super_admin logged in','2026-10-10 09:09:39'),(69,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-10 09:12:31'),(70,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-10 09:16:43'),(71,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-10 09:27:01'),(72,14,'Login','Super_admin logged into CampusIQ via Google Authenticator','2026-10-10 09:32:30'),(73,22,'OTP Requested','Password reset OTP requested. Dispatch status: Sent','2026-10-10 10:20:07'),(74,22,'Password Reset Completed','User successfully updated password using verified OTP','2026-10-10 10:20:54'),(75,22,'Login','Student logged into CampusIQ via Gmail OTP','2026-10-10 10:21:50'),(76,22,'System Concern Reported','Report #2 (performance): the system is really working slow','2026-10-10 10:23:42'),(77,14,'Login','Super_admin logged into CampusIQ via Gmail OTP','2026-10-10 10:24:29'),(78,22,'Login','Student logged into CampusIQ via Gmail OTP','2026-10-10 11:42:38');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `announcements`
--

DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `author_id` int(11) NOT NULL,
  `audience` enum('all','teachers','students','parents','admins') NOT NULL DEFAULT 'all',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `idx_announcements_status` (`status`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `announcements`
--

LOCK TABLES `announcements` WRITE;
/*!40000 ALTER TABLE `announcements` DISABLE KEYS */;
INSERT INTO `announcements` VALUES (1,'School Reopening','The school will reopen for the new term on 1st September 2025.',1,'all','published','2026-09-01 15:15:52','2026-09-01 15:15:52'),(2,'Science Fair','The annual science fair is scheduled for 18th October 2025.',2,'students','published','2026-09-01 15:15:52','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `announcements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignment_submissions`
--

DROP TABLE IF EXISTS `assignment_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('submitted','late','graded') NOT NULL DEFAULT 'submitted',
  `grade` varchar(20) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_submission` (`assignment_id`,`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `assignment_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignment_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignment_submissions`
--

LOCK TABLES `assignment_submissions` WRITE;
/*!40000 ALTER TABLE `assignment_submissions` DISABLE KEYS */;
INSERT INTO `assignment_submissions` VALUES (1,1,1,'2026-10-08 12:07:32',NULL,'Completed all algebra equations in notebook.','graded','A','Excellent mathematical proofs!','2026-10-08 12:07:32');
/*!40000 ALTER TABLE `assignment_submissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `due_date` datetime NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','closed') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `subject_id` (`subject_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `idx_assignments_class` (`class_id`),
  CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assignments`
--

LOCK TABLES `assignments` WRITE;
/*!40000 ALTER TABLE `assignments` DISABLE KEYS */;
INSERT INTO `assignments` VALUES (1,'Algebra Practice Set 1','Complete problems 1 to 20 from textbook page 45.',1,1,1,'2026-10-15 12:07:32',NULL,'published','2026-10-08 12:07:32','2026-10-08 12:07:32');
/*!40000 ALTER TABLE `assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `status` enum('Present','Absent','Late','Excused') NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_from_status` enum('Present','Absent','Late','Excused') DEFAULT NULL,
  `update_reason` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_student_date` (`student_id`,`date`),
  KEY `recorded_by` (`recorded_by`),
  KEY `updated_by` (`updated_by`),
  KEY `idx_attendance_class_date` (`class_id`,`date`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (1,1,1,'2025-09-10','Present','On time',2,NULL,NULL,NULL,NULL,'2026-09-01 15:15:52'),(2,2,1,'2025-09-10','Late','10 minutes late',2,NULL,NULL,NULL,NULL,'2026-09-01 15:15:52'),(3,3,3,'2025-09-10','Present','Excelling',3,NULL,NULL,NULL,NULL,'2026-09-01 15:15:52'),(4,1,1,'2025-09-11','Absent','Sick leave',2,NULL,NULL,NULL,NULL,'2026-09-01 15:15:52'),(5,2,1,'2025-09-11','Present','Participated in class',2,NULL,NULL,NULL,NULL,'2026-09-01 15:15:52');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup_settings`
--

DROP TABLE IF EXISTS `backup_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auto_backup_enabled` tinyint(1) DEFAULT 1,
  `frequency` enum('daily','weekly','manual') DEFAULT 'daily',
  `retention_days` int(11) DEFAULT 14,
  `local_secondary_path` varchar(255) DEFAULT NULL,
  `cloud_storage_type` enum('none','cloudflare_r2','aws_s3','email') DEFAULT 'email',
  `cloud_endpoint` varchar(255) DEFAULT '',
  `cloud_bucket` varchar(150) DEFAULT '',
  `cloud_access_key` varchar(255) DEFAULT '',
  `cloud_secret_key` varchar(255) DEFAULT '',
  `cloud_region` varchar(50) DEFAULT 'auto',
  `email_backup_recipient` varchar(150) DEFAULT 'swanzyak47@gmail.com',
  `cron_token` varchar(64) DEFAULT 'campus_cron_secure_key_2026',
  `last_backup_at` datetime DEFAULT NULL,
  `last_backup_status` varchar(100) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_settings`
--

LOCK TABLES `backup_settings` WRITE;
/*!40000 ALTER TABLE `backup_settings` DISABLE KEYS */;
INSERT INTO `backup_settings` VALUES (1,1,'daily',1,NULL,'cloudflare_r2','https://3112ca0b2fdf926310078c6a45bbd2e2.r2.cloudflarestorage.com','campusiq-backups','a21826297401d839c510e6b008b0d25e','a07fb4a3186d2d940f471d1f5c21cd6fd2ce9c03742a3869ec96229b09c28f1f','auto','swanzyak47@gmail.com','campus_cron_secure_key_2026','2026-10-10 09:09:46','Synced to CLOUDFLARE_R2','2026-10-10 09:09:46');
/*!40000 ALTER TABLE `backup_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backups`
--

DROP TABLE IF EXISTS `backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `backup_type` enum('database','full') DEFAULT 'database',
  `status` enum('local_only','synced_to_cloud','failed') DEFAULT 'local_only',
  `cloud_provider` varchar(50) DEFAULT 'none',
  `cloud_key` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backups`
--

LOCK TABLES `backups` WRITE;
/*!40000 ALTER TABLE `backups` DISABLE KEYS */;
INSERT INTO `backups` VALUES (12,'campusiq_db_2026-10-10_11-09-39.sql.gz','C:\\xampp\\htdocs\\campusIQ/storage/backups/campusiq_db_2026-10-10_11-09-39.sql.gz',11543,'database','synced_to_cloud','cloudflare_r2','campusiq_db_2026-10-10_11-09-39.sql.gz',NULL,'2026-10-10 09:09:40');
/*!40000 ALTER TABLE `backups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `level` varchar(100) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `class_teacher_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_teacher_id` (`class_teacher_id`),
  KEY `department_id` (`department_id`),
  KEY `idx_classes_name` (`name`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`class_teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (1,'JHS 1A','JHS 1','A',NULL,2,'active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(2,'JHS 1B','JHS 1','B',NULL,2,'active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(3,'SHS 1 Science','SHS 1','Science',NULL,3,'active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(4,'SHS 1 Business','SHS 1','Business',NULL,3,'active','2026-09-01 15:15:52','2026-09-01 16:58:09');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Primary','Primary school department','2026-09-01 15:15:51','2026-09-01 15:15:51'),(2,'Junior High School','JHS department','2026-09-01 15:15:51','2026-09-01 15:15:51'),(3,'Senior High School','SHS department','2026-09-01 15:15:51','2026-09-01 15:15:51');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `term_id` int(11) DEFAULT NULL,
  `status` enum('active','completed','dropped') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `term_id` (`term_id`),
  KEY `idx_enrollments_student` (`student_id`),
  KEY `idx_enrollments_class` (`class_id`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_3` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrollments_ibfk_4` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
INSERT INTO `enrollments` VALUES (1,1,1,2,1,'active','2026-09-01 15:15:52'),(2,2,1,2,1,'active','2026-09-01 15:15:52'),(3,3,3,2,1,'active','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` datetime NOT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
INSERT INTO `events` VALUES (1,'PTA Meeting','Parents and teachers meeting for Term 1 updates','2025-09-18 15:00:00','Main Hall',1,'2026-09-01 15:15:52'),(2,'Sports Day','Inter-house sports competition','2025-10-08 09:00:00','School Field',1,'2026-09-01 15:15:52');
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `examinations`
--

DROP TABLE IF EXISTS `examinations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `examinations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `term_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `exam_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `term_id` (`term_id`),
  KEY `academic_year_id` (`academic_year_id`),
  CONSTRAINT `examinations_ibfk_1` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examinations_ibfk_2` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `examinations`
--

LOCK TABLES `examinations` WRITE;
/*!40000 ALTER TABLE `examinations` DISABLE KEYS */;
INSERT INTO `examinations` VALUES (1,'Mid-Term Exam',1,2,'2025-10-21','First assessment for the term','2026-09-01 15:15:52'),(2,'End-of-Term Exam',1,2,'2025-12-12','Final assessment for the term','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `examinations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_categories`
--

DROP TABLE IF EXISTS `fee_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fee_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fee_categories`
--

LOCK TABLES `fee_categories` WRITE;
/*!40000 ALTER TABLE `fee_categories` DISABLE KEYS */;
INSERT INTO `fee_categories` VALUES (1,'Tuition','Main school tuition',1500.00,'active','2026-09-01 15:15:52'),(2,'Registration','Initial registration fee',250.00,'active','2026-09-01 15:15:52'),(3,'Examination','Exam and assessment fee',180.00,'active','2026-09-01 15:15:52'),(4,'Library','Library maintenance fee',110.00,'active','2026-09-01 15:15:52'),(5,'Transportation','School bus service',300.00,'active','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `fee_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fees`
--

DROP TABLE IF EXISTS `fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `idx_fees_student` (`student_id`),
  KEY `idx_fees_status` (`status`),
  CONSTRAINT `fees_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fees_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `fee_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fees_ibfk_3` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fees`
--

LOCK TABLES `fees` WRITE;
/*!40000 ALTER TABLE `fees` DISABLE KEYS */;
INSERT INTO `fees` VALUES (1,1,1,2,1500.00,'2025-09-30','pending','2026-09-01 15:15:52'),(2,1,2,2,250.00,'2025-09-30','pending','2026-09-01 15:15:52'),(3,2,1,2,1500.00,'2025-09-30','partial','2026-09-01 15:15:52'),(4,3,1,2,1500.00,'2025-09-30','pending','2026-09-01 15:15:52'),(5,6,5,2,300.00,'2026-11-08','pending','2026-10-09 14:23:26');
/*!40000 ALTER TABLE `fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grades`
--

DROP TABLE IF EXISTS `grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_name` varchar(20) NOT NULL,
  `min_score` decimal(5,2) NOT NULL,
  `max_score` decimal(5,2) NOT NULL,
  `remark` varchar(50) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grades`
--

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
INSERT INTO `grades` VALUES (1,'A',80.00,100.00,'Excellent',1,'2026-09-01 15:15:51'),(2,'B',70.00,79.99,'Very Good',0,'2026-09-01 15:15:51'),(3,'C',60.00,69.99,'Good',0,'2026-09-01 15:15:51'),(4,'D',50.00,59.99,'Pass',0,'2026-09-01 15:15:51'),(5,'E',40.00,49.99,'Fair',0,'2026-09-01 15:15:51'),(6,'F',0.00,39.99,'Fail',0,'2026-09-01 15:15:51');
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('draft','issued','paid','cancelled') NOT NULL DEFAULT 'issued',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
INSERT INTO `invoices` VALUES (1,1,'INV-2025-001',1750.00,'issued','2026-09-01 15:15:52'),(2,2,'INV-2025-002',1500.00,'paid','2026-09-01 15:15:52'),(3,3,'INV-2025-003',1500.00,'issued','2026-09-01 15:15:52'),(4,6,'INV-2026-0006',100.00,'paid','2026-10-08 14:24:09');
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `learning_resources`
--

DROP TABLE IF EXISTS `learning_resources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `learning_resources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `class_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(50) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `subject_id` (`subject_id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `learning_resources_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `learning_resources_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `learning_resources_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `learning_resources`
--

LOCK TABLES `learning_resources` WRITE;
/*!40000 ALTER TABLE `learning_resources` DISABLE KEYS */;
INSERT INTO `learning_resources` VALUES (1,'Calculus Basics Guide','Comprehensive guide to limits and differentiation',1,1,1,'assets/uploads/resources/calculus_notes.pdf','PDF',204850,'active','2026-10-08 12:07:32','2026-10-08 12:07:32');
/*!40000 ALTER TABLE `learning_resources` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `receiver_id` (`receiver_id`),
  KEY `idx_messages_user` (`sender_id`,`receiver_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT INTO `messages` VALUES (1,1,2,'Welcome','Welcome to the academic year. Please review the term plan.',0,'2026-09-01 15:15:52','2026-09-01 15:15:52'),(2,2,1,'Attendance','The attendance record is updated for the week.',1,'2026-09-01 15:15:52','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,4,'Fee Reminder','Your term tuition is due soon.','fee',0,'2026-09-01 15:15:52'),(2,2,'New Assignment','Please review the new assignment for JHS 1A.','assignment',0,'2026-09-01 15:15:52'),(3,1,'System Notice','New academic fees were updated in the system.','announcement',1,'2026-09-01 15:15:52');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parents`
--

DROP TABLE IF EXISTS `parents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `relation` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parent_user` (`user_id`),
  CONSTRAINT `parents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parents`
--

LOCK TABLES `parents` WRITE;
/*!40000 ALTER TABLE `parents` DISABLE KEYS */;
INSERT INTO `parents` VALUES (1,7,'Father','Businessman','active','2026-09-01 15:15:52','2026-09-01 15:15:52');
/*!40000 ALTER TABLE `parents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `token` varchar(64) NOT NULL,
  `otp_code` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `token` (`token`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES (1,'swanzyak47@gmail.com','dba09c6c3154890eb059d670104480c1016d84f03b0e31b76568c816f86e4e59',NULL,'2026-10-02 19:00:00','2026-10-02 22:00:00',1),(2,'swanzyak47@gmail.com','01bf198a6f6e1fcb404c283e43b8739b02c5545ad934a6226542642f19a33fcd',NULL,'2026-10-02 19:00:32','2026-10-02 22:00:32',1),(3,'swanzyak47@gmail.com','27b2b20409e45a7ab357c17bfb7f024a9deec0d2f26aeddfd9f5a9da98b4ea52',NULL,'2026-10-02 19:10:20','2026-10-02 22:10:20',1),(4,'swanzyak47@gmail.com','a00210f8aa04d099c630f35d6c50a331d541a21e200a5e4ea367b8cef579c54b','162663','2026-10-02 19:17:17','2026-10-02 21:32:17',1),(5,'swanzyak47@gmail.com','a7fe973cef6822d159b37fb517191d71143484d1e7c2a4e74c330d51138946b6','423923','2026-10-02 19:18:01','2026-10-02 21:34:33',1),(6,'swanzyak47@gmail.com','506028055b89c0b4d1620dbe7c3fa71051d535167de466d4dad309d9e593db3c','914070','2026-10-09 16:40:53','2026-10-09 18:55:53',1),(7,'swanzyak47@gmail.com','c0e96cb4eb75101f72231796a0b8147d5600547408b596a5f0c3aceb2b0e5621','118150','2026-10-09 16:41:04','2026-10-09 18:56:04',1),(8,'swanzyak47@gmail.com','066902be74fba0930e8b935c11df386fe0f81350234dec599f986919d0f3a4ce','423791','2026-10-09 16:41:09','2026-10-09 18:56:09',1),(9,'stephenswanzy2003@gmail.com','90290d259ce3962b789b0cc9839497ea8e4e6a9efcca5ecf05e8736dedd3c695','193521','2026-10-10 10:20:04','2026-10-10 12:35:04',1);
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'GHS',
  `status` varchar(30) NOT NULL DEFAULT 'completed',
  `payment_method` varchar(50) DEFAULT 'Cash',
  `channel` varchar(50) DEFAULT NULL,
  `payment_date` datetime DEFAULT current_timestamp(),
  `reference_number` varchar(100) DEFAULT NULL,
  `paystack_reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `gateway_response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_payment_ref` (`reference_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,2,2,1500.00,'GHS','completed','Mobile Money',NULL,'2026-09-01 15:15:52','MM-92811',NULL,'Term 1 fee settlement',NULL,'2026-09-01 15:15:52'),(2,1,1,500.00,'GHS','completed','Cash',NULL,'2026-09-01 15:15:52','CASH-4752',NULL,'Partial payment',NULL,'2026-09-01 15:15:52'),(3,4,6,100.00,'GHS','completed','MTN Mobile Money (0557560806)',NULL,'2026-10-08 14:24:09','PAY-MM-62BD0A01',NULL,'Term Fee Settlement - delyte zorgadzi',NULL,'2026-10-08 14:24:09');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `results`
--

DROP TABLE IF EXISTS `results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `class_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `exam_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `grade` varchar(10) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result_student_exam_subject` (`student_id`,`exam_id`,`subject_id`),
  KEY `subject_id` (`subject_id`),
  KEY `exam_id` (`exam_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `results_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_3` FOREIGN KEY (`exam_id`) REFERENCES `examinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `results_ibfk_4` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `results`
--

LOCK TABLES `results` WRITE;
/*!40000 ALTER TABLE `results` DISABLE KEYS */;
/*!40000 ALTER TABLE `results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_settings`
--

DROP TABLE IF EXISTS `school_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `school_name` varchar(200) NOT NULL,
  `motto` varchar(255) DEFAULT 'Excellence Through Education',
  `school_logo` varchar(255) DEFAULT NULL,
  `primary_color` varchar(30) DEFAULT '#1d4ed8',
  `address` text DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `website` varchar(200) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'GH???',
  `academic_year_id` int(11) DEFAULT NULL,
  `term_id` int(11) DEFAULT NULL,
  `grading_system` text DEFAULT NULL,
  `paystack_public_key` varchar(255) DEFAULT NULL,
  `paystack_secret_key` varchar(255) DEFAULT NULL,
  `paystack_test_mode` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `academic_year_id` (`academic_year_id`),
  KEY `term_id` (`term_id`),
  CONSTRAINT `school_settings_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `school_settings_ibfk_2` FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_settings`
--

LOCK TABLES `school_settings` WRITE;
/*!40000 ALTER TABLE `school_settings` DISABLE KEYS */;
INSERT INTO `school_settings` VALUES (1,'CampusIQ','Excellence Through Education','assets/images/logo-placeholder.svg','#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-09-01 15:15:51','2026-10-10 10:43:10'),(2,'CampusIQ','Excellence Through Education',NULL,'#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-09-05 15:02:49','2026-10-10 10:43:10'),(3,'CampusIQ','Excellence Through Education',NULL,'#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-10-02 08:41:55','2026-10-10 10:43:10'),(4,'CampusIQ','Excellence Through Education',NULL,'#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-10-02 08:42:24','2026-10-10 10:43:10'),(5,'ADONAI INTERNATIONAL SCHOOL','Excellence Through Education',NULL,'#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-10-02 19:06:24','2026-10-10 10:43:10'),(6,'CAMPUSIQ','Excellence Through Education',NULL,'#1d4ed8','Accra, Greater Accra, Ghana','+233 302 000 000','info@campusiq.edu.gh','https://campusiq.edu.gh','GH₵',2,1,'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39',NULL,NULL,1,'2026-10-03 23:35:46','2026-10-10 10:43:10');
/*!40000 ALTER TABLE `school_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `admission_date` date DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','graduated') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_students_student_id` (`student_id`),
  KEY `idx_students_class` (`class_id`),
  KEY `idx_students_parent` (`parent_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `students_ibfk_3` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (1,4,'STU-001','2012-06-14','Female','Tema Community 4','2024-09-02',1,1,'active','2026-09-01 15:15:52','2026-10-08 11:50:22'),(2,5,'STU-002','2011-11-22','Male','Kasoa','2024-09-02',1,1,'active','2026-09-01 15:15:52','2026-10-08 11:50:22'),(3,6,'STU-003','2013-02-09','Female','East Legon','2024-09-02',3,1,'active','2026-09-01 15:15:52','2026-10-08 11:50:22'),(6,21,'STU-021','2026-10-09','Female',NULL,'2026-10-08',NULL,NULL,'active','2026-10-08 14:20:50','2026-10-08 14:20:50'),(7,22,'STU-022',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-10-10 09:16:58','2026-10-10 09:16:58'),(8,23,'STU-023',NULL,NULL,NULL,NULL,NULL,NULL,'active','2026-10-10 09:33:02','2026-10-10 09:33:02');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subject_teacher_assignments`
--

DROP TABLE IF EXISTS `subject_teacher_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subject_teacher_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subject_teacher_class` (`subject_id`,`teacher_id`,`class_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `subject_teacher_assignments_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_teacher_assignments_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_teacher_assignments_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subject_teacher_assignments`
--

LOCK TABLES `subject_teacher_assignments` WRITE;
/*!40000 ALTER TABLE `subject_teacher_assignments` DISABLE KEYS */;
INSERT INTO `subject_teacher_assignments` VALUES (1,1,1,1,'2026-09-01 15:15:52'),(2,2,1,1,'2026-09-01 15:15:52'),(3,4,2,3,'2026-09-01 15:15:52'),(4,5,2,3,'2026-09-01 15:15:52'),(5,3,3,1,'2026-09-01 16:45:43'),(6,1,2,2,'2026-09-01 16:45:53'),(7,1,3,NULL,'2026-09-01 16:48:44');
/*!40000 ALTER TABLE `subject_teacher_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(50) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `department_id` (`department_id`),
  KEY `idx_subjects_name` (`name`),
  CONSTRAINT `subjects_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,'Mathematics','MATH101',2,'Core mathematics subject','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(2,'English Language','ENG101',2,'Language and communication','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(3,'Integrated Science','SCI101',2,'Science fundamentals','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(4,'Physics','PHY101',3,'Physical science concepts','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(5,'Economics','ECO101',3,'Business and economics principles','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(6,'SOCIALL STUDIES','1122',2,NULL,'active','2026-09-01 17:02:46','2026-09-01 17:02:46');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_reports`
--

DROP TABLE IF EXISTS `system_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(150) NOT NULL,
  `user_role` varchar(50) NOT NULL,
  `user_email` varchar(150) DEFAULT NULL,
  `report_type` enum('bug','feature_request','usability','performance','general') NOT NULL DEFAULT 'bug',
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `priority` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `page_url` varchar(500) DEFAULT NULL,
  `status` enum('pending','reviewing','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`report_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_reports`
--

LOCK TABLES `system_reports` WRITE;
/*!40000 ALTER TABLE `system_reports` DISABLE KEYS */;
INSERT INTO `system_reports` VALUES (1,2,'Mabel Mensah','teacher','teacher1@campusiq.edu.gh','usability','Mobile attendance checkboxes','Can we make the attendance checkbox slightly larger for easy tapping on phone screens?','medium','/teacher/attendance.php','pending',NULL,NULL,NULL,'2026-10-09 18:05:50','2026-10-09 18:05:50'),(2,22,'akwesi swanzy','student','stephenswanzy2003@gmail.com','performance','the system is really working slow','the system is really slow','medium','http://localhost/campusiq/student/fees.php','pending',NULL,NULL,NULL,'2026-10-10 10:23:42','2026-10-10 10:23:42');
/*!40000 ALTER TABLE `system_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `teacher_id` varchar(50) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `qualification` varchar(150) DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_id` (`teacher_id`),
  KEY `user_id` (`user_id`),
  KEY `department_id` (`department_id`),
  KEY `idx_teachers_teacher_id` (`teacher_id`),
  CONSTRAINT `teachers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teachers_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (1,2,'TCH-001',2,'Head of Department','B.Ed Mathematics','2022-09-01','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(2,3,'TCH-002',3,'Senior Teacher','B.Sc Physics','2023-01-15','active','2026-09-01 15:15:52','2026-09-01 15:15:52'),(3,13,'ID001',2,NULL,'DEGREE','2026-09-01','active','2026-09-01 16:41:16','2026-09-01 16:41:16');
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terms`
--

DROP TABLE IF EXISTS `terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_terms_year` (`academic_year_id`),
  CONSTRAINT `terms_ibfk_1` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `terms`
--

LOCK TABLES `terms` WRITE;
/*!40000 ALTER TABLE `terms` DISABLE KEYS */;
INSERT INTO `terms` VALUES (1,2,'Term 1','2025-09-01','2025-12-19',1,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(2,2,'Term 2','2026-01-05','2026-03-27',0,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(3,2,'Term 3','2026-04-13','2026-07-31',0,'2026-09-01 15:15:51','2026-09-01 15:15:51');
/*!40000 ALTER TABLE `terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `totp_secret` text DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_failed_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `totp_locked_until` datetime DEFAULT NULL,
  `sms_otp` varchar(10) DEFAULT NULL,
  `sms_otp_expires_at` datetime DEFAULT NULL,
  `email_otp` varchar(10) DEFAULT NULL,
  `email_otp_expires_at` datetime DEFAULT NULL,
  `role` enum('super_admin','school_admin','teacher','student','parent','accountant') NOT NULL,
  `status` enum('active','inactive','pending') NOT NULL DEFAULT 'active',
  `phone` varchar(30) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'System Administrator','admin@campusiq.edu.gh','$2y$10$7vjno6AiyZXoIS5y45sqTuUyrV21nkCKqD5wFhbsXk8wjBHOFvw8S',NULL,0,0,NULL,'277022','2026-10-02 22:38:34','104910','2026-10-07 13:25:46','super_admin','active','+233 200 100 001','uploads/avatars/avatar_1_73b50c32.jpg','2026-09-01 15:15:51','2026-10-09 14:59:38'),(2,'Mabel Mensah','teacher1@campusiq.edu.gh','$2y$10$ZsrBMl6kG2gtqq1vDNTCu.vkN36cpj2cbenJ1NrDaEeznrB8quE7a',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'teacher','active','+233 200 100 002',NULL,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(3,'Kwame Asante','teacher2@campusiq.edu.gh','$2y$10$ZsrBMl6kG2gtqq1vDNTCu.vkN36cpj2cbenJ1NrDaEeznrB8quE7a',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'teacher','active','+233 200 100 003',NULL,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(4,'Esi Boateng','student1@campusiq.edu.gh','$2y$10$DUlQfHqEd8qzp7cc9UEQyu00XFW7Emt6y6WKqowcfPf4/1ooRnXIC',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'student','active','+233 200 100 004',NULL,'2026-09-01 15:15:51','2026-10-09 22:54:49'),(5,'Kojo Nkrumah','student2@campusiq.edu.gh','$2y$10$QGhmEj7kpRdam/1FYS1cwuCJQwbHfLFOn15ckIVv.VS.lkvW9Wg.6',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'student','active','+233 200 100 005',NULL,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(6,'Ama Serwah','student3@campusiq.edu.gh','$2y$10$QGhmEj7kpRdam/1FYS1cwuCJQwbHfLFOn15ckIVv.VS.lkvW9Wg.6',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'student','active','+233 200 100 006',NULL,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(7,'Daniel Adjei','parent1@campusiq.edu.gh','$2y$10$uaq.A8v8nq4nwtT/lgasK.m3.du1Nrt3fP5tKcsw9aNjFYnV7iAtq',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'parent','active','+233 200 100 007',NULL,'2026-09-01 15:15:51','2026-10-09 23:32:34'),(9,'Afua Owusu','accountant@campusiq.edu.gh','$2y$10$iVQF5hC1sNxSL9xsnWXrSeIemRpxpUDENFnbN/kMs9ebdi/UnoNnq',NULL,0,0,NULL,NULL,NULL,NULL,NULL,'accountant','active','+233 200 100 008',NULL,'2026-09-01 15:15:51','2026-09-01 15:15:51'),(10,'akwesi sean paul','Akwesi@gmail.com','$2y$10$4e2CSuOi2eZGOffk.hmbeeXGmbvBg056t2FQ5LASVZsiFtBdGIBDu',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'student','active','0204537810',NULL,'2026-09-01 15:24:38','2026-10-09 22:50:50'),(11,'paul swanzy','paul@gmail.com','$2y$10$V7ce5y5jMYSVfOBnAhkl5udXFlz9j0ENOl5hklBB8MWFk0wIaQ6hu',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'teacher','active','0204537810',NULL,'2026-09-01 15:27:42','2026-10-09 22:50:50'),(12,'mennsua','mennsua@gmai.com','$2y$10$8ID.moet5jhQa/vtZ0mX1.BmiLicaYZONxmQlXzfV3fAURwTgSm.e',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'teacher','active','0204537810',NULL,'2026-09-01 15:48:14','2026-10-09 22:50:50'),(13,'olivia swanzy','oliviaswanzy@gmail.com','$2y$10$7NPGLhelXTpDvheEetCy2eP9gfuD9cIr6FsWohAGM7HSTHqiP.dKS',NULL,1,0,NULL,NULL,NULL,'943962','2026-10-10 01:06:36','teacher','active','024537580',NULL,'2026-09-01 16:41:16','2026-10-09 22:51:36'),(14,'Stephen Swanzy','swanzyak47@gmail.com','$2y$10$mZMcPLIcXVCNPIRlKNq/u.Qo.Xmc/IPjFCQXs6zxhYLKsh/FQQ9pu','8ZNuU8YrLwf+P+9I7btHu7Ed4FGfNCOzKNO+qtdKfHWjlE/6TRlZQ9dBPewEb4uwPb7p2FIzh9kMoa8m',1,0,NULL,NULL,NULL,NULL,NULL,'super_admin','active','+233 557 560806','uploads/avatars/avatar_14_d9d6e91a.jpg','2026-10-02 18:10:00','2026-10-10 10:24:29'),(15,'dickson dogbe','dicksondogbe49@gmail.com','$2y$10$TDXc3/v6Xv3ROJ8Lc/V4deEFBmjmOHbKUkOGH7naOSPWrEm68dpkO',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'teacher','active','0257633076',NULL,'2026-10-07 20:38:52','2026-10-09 22:50:50'),(21,'delyte zorgadzi','delytezorgadzi@gmail.com','$2y$10$1nE1gMYifXAu7D4m6JbHxu/nWVzlWXZiaZmwXUfNbd/ZtigAzoMUy',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'student','active','0541568134',NULL,'2026-10-08 14:20:50','2026-10-09 22:50:50'),(22,'akwesi swanzy','stephenswanzy2003@gmail.com','$2y$10$4sOlegJWkZYvg7beRN0yye7XkviVNYrQDkiwrSp2TeGrW6eWSWXZK',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'student','active','0204537810',NULL,'2026-10-10 09:16:58','2026-10-10 11:42:38'),(23,'Saviour Semakor','savioursemakor24@gmail.com','$2y$10$lqvNwBUScFY1FzcyJgxImumJUK3Y7bYniKrYkpb2WxwZegGV26Po6',NULL,1,0,NULL,NULL,NULL,NULL,NULL,'student','active','0249242084',NULL,'2026-10-10 09:33:02','2026-10-10 09:33:02');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'campusiq'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-10 11:49:09
