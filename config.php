<?php
declare(strict_types=1);

/**
 * FlashDL Config
 */
const APP_NAME = 'Flash Copy DL';
const BASE_URL = 'https://dl2.flash-copy.fr'; // adapte si besoin

// Dossiers (dans le site)
const DATA_DIR    = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const UPLOADS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

// Taille max par fichier (en octets)
const MAX_FILE_BYTES = 60 * 1024 * 1024 * 1024; // 5 Go

// Extensions autorisées (null = tout). Exemple: ['zip','7z','pdf','jpg','png']
const ALLOWED_EXT = null;

// Admin bootstrap
const ADMIN_USERNAME = 'admin';
// Mot de passe initial admin (sera hashé en DB au 1er lancement si admin absent)
const ADMIN_INITIAL_PASSWORD = 'Fc21340';
