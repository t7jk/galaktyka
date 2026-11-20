<?php
/**
 * Magazyn danych JSON w plikach data/*.php (hasła, tokeny, SMTP) - bez bazy danych.
 * Pułapka bezpieczeństwa: serwer (Apache z AllowOverride None, a czasem i LiteSpeed) może ignorować data/.htaccess,
 * a pliki *.config są wtedy pobieralne z WWW. Dlatego sekrety trzymamy w plikach .php, których pierwsza linia
 * kończy skrypt (GUARD) - przy bezpośrednim wejściu z przeglądarki serwer zwraca 404 i nic nie ujawnia.
 * Zapisy są pod flock, bo PHP obsługuje żądania równolegle.
 */
const STORE_GUARD = "<?php http_response_code(404); exit; ?>\n";

/** Czyta plik magazynu (tablica), brak lub uszkodzony plik = []. */
function json_read(string $file): array
{
    $raw = is_file($file) ? (string)file_get_contents($file) : '';
    $d   = json_decode(str_starts_with($raw, STORE_GUARD) ? substr($raw, strlen(STORE_GUARD)) : $raw, true);
    return is_array($d) ? $d : [];
}

/** Odczyt-modyfikacja-zapis pod flock. $fn dostaje tablicę przez referencję. */
function json_update(string $file, callable $fn): void
{
    $fp = fopen($file, 'c+');
    if (!$fp) throw new RuntimeException("Nie można otworzyć $file");
    flock($fp, LOCK_EX);
    $raw = (string)stream_get_contents($fp);
    $d   = json_decode(str_starts_with($raw, STORE_GUARD) ? substr($raw, strlen(STORE_GUARD)) : $raw, true);
    $d   = is_array($d) ? $d : [];
    $fn($d);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, STORE_GUARD . json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($file, 0660);
}
