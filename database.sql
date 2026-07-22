-- Création de la base de données (si elle n'existe pas)
CREATE DATABASE IF NOT EXISTS jobmentor_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jobmentor_db;

-- Table des utilisateurs
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    titre VARCHAR(200) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    secteur VARCHAR(50) DEFAULT NULL,
    photo LONGTEXT DEFAULT NULL,
    api_key_encrypted VARCHAR(500) DEFAULT NULL,
    api_provider ENUM('openrouter','openai','anthropic') DEFAULT 'openrouter',
    role ENUM('user','admin') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Migration : si la table existe déjà, ajouter les colonnes manquantes
ALTER TABLE users ADD COLUMN IF NOT EXISTS titre VARCHAR(200) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS secteur VARCHAR(50) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS photo LONGTEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user','admin') NOT NULL DEFAULT 'user';
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Pour créer ton premier compte admin, inscris-toi normalement puis exécute :
-- UPDATE users SET role = 'admin' WHERE email = 'ton-email@exemple.com';

-- Table des CVs (un utilisateur peut en générer plusieurs)
CREATE TABLE IF NOT EXISTS cv_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL, -- NULL si généré anonymement (optionnel)
    title VARCHAR(255) DEfAULT 'Mon CV',
    json_content JSON NOT NULL, -- Stocke la structure complète du CV (nom, exp, etc.)
    score_ats INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table des lettres de motivation
CREATE TABLE IF NOT EXISTS cover_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    job_offer TEXT,
    content TEXT NOT NULL,
    score INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Historique des entretiens simulés
CREATE TABLE IF NOT EXISTS interview_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    job_title VARCHAR(255) NOT NULL,
    global_score INT DEFAULT 0,
    transcript JSON NOT NULL, -- Historique de l'échange sous format JSON
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notes personnelles
CREATE TABLE IF NOT EXISTS user_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Historique des analyses d'entraînement oral (module "Entraînement Oral")
-- NB : cette table était auparavant créée par un script de debug non protégé
-- (backend/api/init_db_oral.php), supprimé pour raisons de sécurité. Sa
-- définition est désormais ici, dans le schéma officiel du projet.
CREATE TABLE IF NOT EXISTS oral_analyses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    job_title VARCHAR(255),
    transcription TEXT,
    analysis_json LONGTEXT,
    score_global INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

