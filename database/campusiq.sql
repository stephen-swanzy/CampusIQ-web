CREATE DATABASE IF NOT EXISTS campusiq CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE campusiq;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    totp_secret TEXT DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    totp_locked_until DATETIME DEFAULT NULL,
    role ENUM('super_admin','school_admin','teacher','student','parent','accountant') NOT NULL,
    status ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    phone VARCHAR(30) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email),
    INDEX idx_users_role (role)
);

CREATE TABLE access_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    role ENUM('teacher','student','parent','accountant','school_admin') NOT NULL,
    request_type VARCHAR(50) NOT NULL,
    student_name VARCHAR(150) DEFAULT NULL,
    class_name VARCHAR(100) DEFAULT NULL,
    message TEXT,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_access_requests_email (email),
    INDEX idx_access_requests_status (status),
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE account_activations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_request_id (request_id),
    FOREIGN KEY (request_id) REFERENCES access_requests(id) ON DELETE CASCADE
);

CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    INDEX idx_terms_year (academic_year_id)
);

CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    level VARCHAR(100) DEFAULT NULL,
    section VARCHAR(50) DEFAULT NULL,
    class_teacher_id INT DEFAULT NULL,
    department_id INT DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_classes_name (name)
);

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id VARCHAR(50) NOT NULL UNIQUE,
    dob DATE DEFAULT NULL,
    gender ENUM('Male','Female','Other') DEFAULT NULL,
    address TEXT,
    admission_date DATE DEFAULT NULL,
    class_id INT DEFAULT NULL,
    parent_id INT DEFAULT NULL,
    status ENUM('active','inactive','graduated') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_students_student_id (student_id),
    INDEX idx_students_class (class_id),
    INDEX idx_students_parent (parent_id)
);

CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    teacher_id VARCHAR(50) NOT NULL UNIQUE,
    department_id INT DEFAULT NULL,
    designation VARCHAR(100) DEFAULT NULL,
    qualification VARCHAR(150) DEFAULT NULL,
    hire_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_teachers_teacher_id (teacher_id)
);

CREATE TABLE parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    relation VARCHAR(50) DEFAULT NULL,
    occupation VARCHAR(100) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_parent_user (user_id)
);

CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    department_id INT DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    INDEX idx_subjects_name (name)
);

CREATE TABLE subject_teacher_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    class_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_subject_teacher_class (subject_id, teacher_id, class_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    term_id INT DEFAULT NULL,
    status ENUM('active','completed','dropped') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL,
    INDEX idx_enrollments_student (student_id),
    INDEX idx_enrollments_class (class_id)
);

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('Present','Absent','Late','Excused') NOT NULL,
    remarks VARCHAR(255) DEFAULT NULL,
    recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_attendance_student_date (student_id, date),
    INDEX idx_attendance_class_date (class_id, date)
);

CREATE TABLE examinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    term_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    exam_date DATE DEFAULT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
);

CREATE TABLE grades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_name VARCHAR(20) NOT NULL,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    remark VARCHAR(50) DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_id INT NOT NULL,
    exam_id INT NOT NULL,
    class_id INT NOT NULL,
    class_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    exam_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    grade VARCHAR(10) DEFAULT NULL,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES examinations(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_result_student_exam_subject (student_id, exam_id, subject_id)
);

CREATE TABLE assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    due_date DATETIME NOT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    INDEX idx_assignments_class (class_id)
);

CREATE TABLE assignment_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at DATETIME DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    status ENUM('submitted','late','graded') NOT NULL DEFAULT 'submitted',
    grade VARCHAR(20) DEFAULT NULL,
    feedback TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY uq_assignment_submission (assignment_id, student_id)
);

CREATE TABLE learning_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    status ENUM('active','archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

CREATE TABLE fee_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    category_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES fee_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    INDEX idx_fees_student (student_id),
    INDEX idx_fees_status (status)
);

CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('draft','issued','paid','cancelled') NOT NULL DEFAULT 'issued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT DEFAULT NULL,
    student_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'Cash',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    reference_number VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    author_id INT NOT NULL,
    audience ENUM('all','teachers','students','parents','admins') NOT NULL DEFAULT 'all',
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_announcements_status (status)
);

CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200) DEFAULT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_user (sender_id, receiver_id)
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id)
);

CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    event_date DATETIME NOT NULL,
    venue VARCHAR(200) DEFAULT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE school_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_name VARCHAR(200) NOT NULL,
    school_logo VARCHAR(255) DEFAULT NULL,
    address TEXT,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    website VARCHAR(200) DEFAULT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'GH₵',
    academic_year_id INT DEFAULT NULL,
    term_id INT DEFAULT NULL,
    grading_system TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE SET NULL
);

CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(200) NOT NULL,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_activity_logs_user (user_id)
);

INSERT INTO departments (name, description) VALUES
('Primary', 'Primary school department'),
('Junior High School', 'JHS department'),
('Senior High School', 'SHS department');

INSERT INTO academic_years (name, start_date, end_date, is_current) VALUES
('2024/2025', '2024-09-01', '2025-07-31', 0),
('2025/2026', '2025-09-01', '2026-07-31', 1);

INSERT INTO terms (academic_year_id, name, start_date, end_date, is_current) VALUES
(2, 'Term 1', '2025-09-01', '2025-12-19', 1),
(2, 'Term 2', '2026-01-05', '2026-03-27', 0),
(2, 'Term 3', '2026-04-13', '2026-07-31', 0);

INSERT INTO grades (grade_name, min_score, max_score, remark, is_default) VALUES
('A', 80, 100, 'Excellent', 1),
('B', 70, 79.99, 'Very Good', 0),
('C', 60, 69.99, 'Good', 0),
('D', 50, 59.99, 'Pass', 0),
('E', 40, 49.99, 'Fair', 0),
('F', 0, 39.99, 'Fail', 0);

INSERT INTO school_settings (school_name, school_logo, address, phone, email, website, currency, academic_year_id, term_id, grading_system)
VALUES ('CampusIQ', 'assets/images/logo-placeholder.svg', 'Accra, Greater Accra, Ghana', '+233 302 000 000', 'info@campusiq.edu.gh', 'https://campusiq.edu.gh', 'GH₵', 2, 1, 'A:80-100,B:70-79,C:60-69,D:50-59,E:40-49,F:0-39');

INSERT INTO users (name, email, password_hash, role, phone, status) VALUES
('System Administrator', 'admin@campusiq.edu.gh', '$2y$10$AQRrzimM9vNCdM2th8nSBuo9Trcga/1FyxcYoFT1UgDMNVou5lNqa', 'super_admin', '+233 200 100 001', 'active'),
('Mabel Mensah', 'teacher1@campusiq.edu.gh', '$2y$10$ZsrBMl6kG2gtqq1vDNTCu.vkN36cpj2cbenJ1NrDaEeznrB8quE7a', 'teacher', '+233 200 100 002', 'active'),
('Kwame Asante', 'teacher2@campusiq.edu.gh', '$2y$10$ZsrBMl6kG2gtqq1vDNTCu.vkN36cpj2cbenJ1NrDaEeznrB8quE7a', 'teacher', '+233 200 100 003', 'active'),
('Esi Boateng', 'student1@campusiq.edu.gh', '$2y$10$QGhmEj7kpRdam/1FYS1cwuCJQwbHfLFOn15ckIVv.VS.lkvW9Wg.6', 'student', '+233 200 100 004', 'active'),
('Kojo Nkrumah', 'student2@campusiq.edu.gh', '$2y$10$QGhmEj7kpRdam/1FYS1cwuCJQwbHfLFOn15ckIVv.VS.lkvW9Wg.6', 'student', '+233 200 100 005', 'active'),
('Ama Serwah', 'student3@campusiq.edu.gh', '$2y$10$QGhmEj7kpRdam/1FYS1cwuCJQwbHfLFOn15ckIVv.VS.lkvW9Wg.6', 'student', '+233 200 100 006', 'active'),
('Daniel Adjei', 'parent1@campusiq.edu.gh', '$2y$10$/B8XWbiJV62aJmiK8T/RCe0FpnAZec1/DiC.P1d8r9yB.cWaJ7rcm', 'parent', '+233 200 100 007', 'active'),
('Afua Owusu', 'accountant@campusiq.edu.gh', '$2y$10$iVQF5hC1sNxSL9xsnWXrSeIemRpxpUDENFnbN/kMs9ebdi/UnoNnq', 'accountant', '+233 200 100 008', 'active');

INSERT INTO classes (name, level, section, department_id) VALUES
('JHS 1A', 'JHS 1', 'A', 2),
('JHS 1B', 'JHS 1', 'B', 2),
('SHS 1 Science', 'SHS 1', 'Science', 3),
('SHS 1 Business', 'SHS 1', 'Business', 3);

INSERT INTO teachers (user_id, teacher_id, department_id, designation, qualification, hire_date, status) VALUES
(2, 'TCH-001', 2, 'Head of Department', 'B.Ed Mathematics', '2022-09-01', 'active'),
(3, 'TCH-002', 3, 'Senior Teacher', 'B.Sc Physics', '2023-01-15', 'active');

INSERT INTO parents (user_id, relation, occupation, status) VALUES
(7, 'Father', 'Businessman', 'active');

INSERT INTO subjects (name, code, department_id, description) VALUES
('Mathematics', 'MATH101', 2, 'Core mathematics subject'),
('English Language', 'ENG101', 2, 'Language and communication'),
('Integrated Science', 'SCI101', 2, 'Science fundamentals'),
('Physics', 'PHY101', 3, 'Physical science concepts'),
('Economics', 'ECO101', 3, 'Business and economics principles');

INSERT INTO students (user_id, student_id, dob, gender, address, admission_date, class_id, parent_id, status) VALUES
(4, 'STU-001', '2012-06-14', 'Female', 'Tema Community 4', '2024-09-02', 1, 7, 'active'),
(5, 'STU-002', '2011-11-22', 'Male', 'Kasoa', '2024-09-02', 1, 7, 'active'),
(6, 'STU-003', '2013-02-09', 'Female', 'East Legon', '2024-09-02', 3, 7, 'active');

INSERT INTO subject_teacher_assignments (subject_id, teacher_id, class_id) VALUES
(1, 1, 1),
(2, 1, 1),
(4, 2, 3),
(5, 2, 3);

INSERT INTO enrollments (student_id, class_id, academic_year_id, term_id, status) VALUES
(1, 1, 2, 1, 'active'),
(2, 1, 2, 1, 'active'),
(3, 3, 2, 1, 'active');

INSERT INTO attendance (student_id, class_id, date, status, remarks, recorded_by) VALUES
(1, 1, '2025-09-10', 'Present', 'On time', 2),
(2, 1, '2025-09-10', 'Late', '10 minutes late', 2),
(3, 3, '2025-09-10', 'Present', 'Excelling', 3),
(1, 1, '2025-09-11', 'Absent', 'Sick leave', 2),
(2, 1, '2025-09-11', 'Present', 'Participated in class', 2);

INSERT INTO examinations (name, term_id, academic_year_id, exam_date, description) VALUES
('Mid-Term Exam', 1, 2, '2025-10-21', 'First assessment for the term'),
('End-of-Term Exam', 1, 2, '2025-12-12', 'Final assessment for the term');

INSERT INTO fee_categories (name, description, amount) VALUES
('Tuition', 'Main school tuition', 1500.00),
('Registration', 'Initial registration fee', 250.00),
('Examination', 'Exam and assessment fee', 180.00),
('Library', 'Library maintenance fee', 110.00),
('Transportation', 'School bus service', 300.00);

INSERT INTO fees (student_id, category_id, academic_year_id, amount, due_date, status) VALUES
(1, 1, 2, 20,00000, '2025-09-30', 'pending'),
(1, 2, 2, 250.00, '2025-09-30', 'paid'),
(2, 1, 2, 1500.00, '2025-09-30', 'partial'),
(3, 1, 2, 1500.00, '2025-09-30', 'pending');

INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES
(1, 'INV-2025-001', 1750.00, 'issued'),
(2, 'INV-2025-002', 1500.00, 'paid'),
(3, 'INV-2025-003', 1500.00, 'issued');

INSERT INTO payments (invoice_id, student_id, amount, payment_method, reference_number, notes) VALUES
(2, 2, 1500.00, 'Mobile Money', 'MM-92811', 'Term 1 fee settlement'),
(1, 1, 500.00, 'Cash', 'CASH-4752', 'Partial payment');

INSERT INTO announcements (title, message, author_id, audience, status) VALUES
('School Reopening', 'The school will reopen for the new term on 1st September 2025.', 1, 'all', 'published'),
('Science Fair', 'The annual science fair is scheduled for 18th October 2025.', 2, 'students', 'published');

INSERT INTO events (title, description, event_date, venue, created_by) VALUES
('PTA Meeting', 'Parents and teachers meeting for Term 1 updates', '2025-09-18 15:00:00', 'Main Hall', 1),
('Sports Day', 'Inter-house sports competition', '2025-10-08 09:00:00', 'School Field', 1);

INSERT INTO messages (sender_id, receiver_id, subject, message, is_read) VALUES
(1, 2, 'Welcome', 'Welcome to the academic year. Please review the term plan.', 0),
(2, 1, 'Attendance', 'The attendance record is updated for the week.', 1);

INSERT INTO notifications (user_id, title, message, type, is_read) VALUES
(4, 'Fee Reminder', 'Your term tuition is due soon.', 'fee', 0),
(2, 'New Assignment', 'Please review the new assignment for JHS 1A.', 'assignment', 0),
(1, 'System Notice', 'New academic fees were updated in the system.', 'announcement', 1);

INSERT INTO activity_logs (user_id, action, details) VALUES
(1, 'Login', 'Administrator logged into CampusIQ'),
(2, 'Attendance', 'Attendance recorded for class JHS 1A'),
(4, 'Fee View', 'Student viewed fee statement');
