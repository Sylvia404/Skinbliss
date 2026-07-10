-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 10, 2026 at 09:57 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `skinbliss`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password_hash`) VALUES
(1, 'admin', '$2a$12$jJOXkE05aGXpEwyG5ttI2eSyEHzBmpz1b790SKlRh7yKRWsF0xaMi');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` varchar(20) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `region` varchar(50) NOT NULL,
  `address` text NOT NULL,
  `payment_method` varchar(30) NOT NULL,
  `total` int(11) NOT NULL,
  `placed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `customer_name`, `phone`, `region`, `address`, `payment_method`, `total`, `placed_at`) VALUES
('SB26070866bb', 'Sylvia louis Salu', '0688054087', 'Dar es Salaam', 'ARUSHA', 'M-Pesa', 380000, '2026-07-08 19:39:16'),
('SB260709df3c', 'Sylvia louis Salu', '0688054087', 'Arusha', 'ARUSHA', 'M-Pesa', 365000, '2026-07-09 11:52:52');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` varchar(20) NOT NULL,
  `product_id` varchar(20) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `price`) VALUES
(1, 'SB26070866bb', 'pb8e832', 1, 380000),
(2, 'SB260709df3c', 'p4a4763', 1, 365000);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('skincare','body','packages') NOT NULL,
  `size` varchar(30) NOT NULL,
  `price` int(11) NOT NULL,
  `desc` text NOT NULL,
  `badge` varchar(50) DEFAULT '',
  `tint` varchar(7) DEFAULT '#F6D6DE',
  `image` varchar(255) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category`, `size`, `price`, `desc`, `badge`, `tint`, `image`) VALUES
('p08aa18', 'Body Oil.', 'body', 'N/A', 65000, 'it works great for brown skin', 'Bestseller', '#F6D6DE', 'product_6a4e6faf9c336.jpeg'),
('p4a4763', 'Caramel Package', 'packages', 'N/A', 365000, 'Rich, deeply hydrating formulas designed to enhance and nourish deeper dark  skin tones from head to toe.\r\nit comprises of ;\r\nFace cream\r\nFace mask\r\nScrub\r\nSoap\r\n Stretch mark remover\r\nBody lotion\r\nBody oil\r\nShower gel\r\nUnder eye mask\r\nBody butter', 'Bestseller', '#F6D6DE', 'product_6a4e6bdd85603.jpeg'),
('p617772', 'Body  Butter', 'body', 'N/A', 45000, 'it brightens the darkskin and nourshes it', 'Bestseller', '#F6D6DE', 'product_6a4e709a08b04.jpeg'),
('pb120dc', 'Body Scrub', 'body', 'N/A', 35000, 'Makes the skin glow and nourishes it', 'Bestseller', '#F6D6DE', 'product_6a4e70242232c.jpeg'),
('pb8e832', 'Bridal  Package', 'packages', 'N/A', 380000, 'A full glow ritual formulated for warm, brown skin tones , it comprises of\r\nFace cream\r\nFace masks\r\nScrub\r\nBody lotion\r\nBody Oil\r\nShowergel\r\nStretch marks remover\r\nBody butter', 'Bestseller', '#F6D6DE', 'product_6a4e6c07f0ab2.jpeg');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'founder_image', 'founder_image_6a4e717174440.jpeg'),
(2, 'instagram_image_1', 'instagram_image_1_6a4e720791499.jpeg'),
(3, 'instagram_image_2', 'instagram_image_2_6a4e733761ed4.jpeg'),
(4, 'instagram_image_3', 'instagram_image_3_6a4e7356eff23.jpeg'),
(5, 'instagram_image_4', 'instagram_image_4_6a4e737aa7201.jpeg');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
