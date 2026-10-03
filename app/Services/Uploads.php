<?php
declare(strict_types=1);

namespace App\Services;

final class Uploads
{
    private const MAX_BYTES = 6 * 1024 * 1024;

    /** Enregistre une photo dans public/uploads/AAAA/MM et renvoie son URL relative. */
    public static function image(array $file, string $name): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \DomainException('Échec de l\'envoi de la photo.');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new \DomainException('Photo trop lourde (6 Mo maximum).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => throw new \DomainException('Format de photo non accepté (JPG, PNG ou WebP).'),
        };
        $dir = date('Y/m');
        $abs = BASE_PATH . '/public/uploads/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
            throw new \RuntimeException('Dossier public/uploads non accessible en écriture.');
        }
        $filename = preg_replace('/[^A-Za-z0-9-]/', '', $name) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $abs . '/' . $filename)) {
            throw new \RuntimeException('Impossible d\'enregistrer la photo.');
        }
        return '/uploads/' . $dir . '/' . $filename;
    }

    /** Transforme $_FILES['photos'] (tableau multiple) en [index => fichier]. */
    public static function normalize(array $files): array
    {
        $out = [];
        if (!isset($files['name']) || !is_array($files['name'])) {
            return $out;
        }
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[$i] = [
                'name'     => $name,
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
        }
        return $out;
    }
}
