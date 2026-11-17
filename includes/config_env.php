<?php
/**
 * Chargement .env + lecture des paramètres (local, hébergeur, variables serveur).
 */

if (!function_exists('samapiece_load_dotenv_file')) {
    /**
     * Charge un fichier clé=valeur (style .env).
     *
     * @param bool $override Si false : n’écrase pas une variable déjà définie dans l’environnement (hébergeur, Apache).
     *                       Si true : applique toujours (pour .env.local après .env).
     */
    function samapiece_load_dotenv_file($path, $override = false) {
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && ($line[0] === '#' || $line[0] === ';'))) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if ($name === '') {
                continue;
            }
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = stripcslashes(substr($value, 1, -1));
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = str_replace(["\\'", '\\\\'], ["'", '\\'], substr($value, 1, -1));
            }
            if (!$override) {
                $already = getenv($name);
                if ($already !== false && $already !== '') {
                    continue;
                }
            }
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

if (!function_exists('samapiece_load_dotenv_local_allowed')) {
    /**
     * .env.local surcharge .env uniquement en contexte « dev » pour ne pas écraser
     * les variables du panneau d’hébergement en production.
     */
    function samapiece_load_dotenv_local_allowed() {
        $force = getenv('SAMAPIECE_LOAD_DOTENV_LOCAL');
        if ($force !== false && $force !== '') {
            $f = strtolower((string) $force);
            if (in_array($f, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            if (in_array($f, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }
        if (php_sapi_name() === 'cli') {
            return true;
        }
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, '127.0.0.1') === 0) {
            return true;
        }
        return false;
    }
}

if (!function_exists('samapiece_load_dotenv')) {
    /**
     * Charge .env puis, en local uniquement, .env.local (surcharge pour le poste de dev).
     * En ligne : variables Apache / hébergeur restent prioritaires sur .env ; .env.local n’est pas chargé.
     */
    function samapiece_load_dotenv($projectRoot) {
        $base = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR;
        samapiece_load_dotenv_file($base . '.env', false);
        if (samapiece_load_dotenv_local_allowed()) {
            samapiece_load_dotenv_file($base . '.env.local', true);
        }
    }

    /**
     * Priorité : config.local.php (clé présente et valeur non vide) → getenv / $_SERVER / $_ENV → défaut.
     * Chargement : .env puis, en dev, .env.local (voir samapiece_load_dotenv).
     *
     * @param array<string, mixed> $local
     * @param list<string>         $envNames
     */
    function samapiece_config_pick(array $local, $localKey, array $envNames, $default = '') {
        if (array_key_exists($localKey, $local)) {
            $lv = $local[$localKey];
            if ($lv !== null && $lv !== '') {
                return $lv;
            }
        }
        foreach ($envNames as $name) {
            $v = getenv($name);
            if ($v !== false && $v !== '') {
                return $v;
            }
            if (!empty($_SERVER[$name])) {
                return $_SERVER[$name];
            }
            if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
                return $_ENV[$name];
            }
        }
        return $default;
    }
}
