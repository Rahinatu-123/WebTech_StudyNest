CREATE TABLE `activitylog` (
    `logId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `userId` int(11) NOT NULL,
    `entityTypeId` int(11) NOT NULL,
    `entityId` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

CREATE TABLE `courses`(`courseId` int(11) AUTO_INCREMENT PRIMARY KEY, `courseName` varchar(100) NOT NULL
);


CREATE TABLE `topics` (
    `topicId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `courseId` int(11) NOT NULL,
    `topicName` varchar(100) NOT NULL
);


CREATE TABLE `comments` (
    `commentId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `userId` int(11) NOT NULL,
    `parentId` int(11) DEFAULT NULL,
    `entityTypeId` int(11) NOT NULL,
    `entityId` int(11) NOT NULL,
    `commentText` text NOT NULL,
    `isRead` tinyint(1) DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);


CREATE TABLE `majors` (
    `majorId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `majorName` varchar(100) NOT NULL
);

CREATE TABLE `roles` (
    `roleId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `roleName` enum('student', 'admin') NOT NULL
);


CREATE TABLE `users` (
    `userId` int(11) AUTO_INCREMENT PRIMARY KEY,
    `firstName` varchar(50) DEFAULT NULL,
    `lastName` varchar(50) DEFAULT NULL,
    `email` varchar(100) DEFAULT NULL,
    `password` varchar(255) DEFAULT NULL,
    `roleId` int(11) NOT NULL,
    `majorId` int(11) DEFAULT NULL,
    `yearGroup` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
);

ALTER TABLE `activitylog`
ADD CONSTRAINT `fk_activitylog_user` FOREIGN KEY (`userId`) REFERENCES `users`(`userId`);

