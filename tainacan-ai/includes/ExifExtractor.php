<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * EXIF metadata extractor for images
 *
 * Extracts technical data from images such as camera, settings,
 * capture date, GPS location, etc.
 */
class ExifExtractor {

    /**
     * Supported MIME types for EXIF
     */
    private array $supported_types = [
        'image/jpeg',
        'image/jpg',
        'image/tiff',
    ];

    /**
     * Extract EXIF metadata from an image
     */
    public function extract(string $file_path): array {
        if (!file_exists($file_path)) {
            return ['error' => __('File not found.', 'tainacan-ai')];
        }

        if (!function_exists('exif_read_data')) {
            return ['error' => __('PHP EXIF extension is not enabled.', 'tainacan-ai')];
        }

        $mime_type = mime_content_type($file_path);
        if (!in_array($mime_type, $this->supported_types)) {
            return ['supported' => false, 'message' => __('Image type does not support EXIF.', 'tainacan-ai')];
        }

        try {
            $exif = @exif_read_data($file_path, 'ANY_TAG', true);

            if ($exif === false) {
                return ['supported' => true, 'data' => [], 'message' => __('No EXIF data found.', 'tainacan-ai')];
            }

            return [
                'supported' => true,
                'data' => $this->parse_exif_data($exif),
                'raw' => $exif,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Extract EXIF from a WordPress attachment
     */
    public function extract_from_attachment(int $attachment_id): array {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path) {
            return ['error' => __('Attachment file not found.', 'tainacan-ai')];
        }

        return $this->extract($file_path);
    }

    /**
     * Process raw EXIF data into friendly format
     */
    private function parse_exif_data(array $exif): array {
        $parsed = [];

        // Basic image information
        $parsed['imagem'] = [
            'largura' => $exif['COMPUTED']['Width'] ?? null,
            'altura' => $exif['COMPUTED']['Height'] ?? null,
            'orientacao' => $this->get_orientation_text($exif['IFD0']['Orientation'] ?? null),
            'resolucao_x' => $exif['IFD0']['XResolution'] ?? null,
            'resolucao_y' => $exif['IFD0']['YResolution'] ?? null,
            'unidade_resolucao' => $this->get_resolution_unit($exif['IFD0']['ResolutionUnit'] ?? null),
            'espaco_cor' => $this->get_color_space($exif['EXIF']['ColorSpace'] ?? null),
        ];

        // Camera information
        $parsed['camera'] = [
            'fabricante' => $exif['IFD0']['Make'] ?? null,
            'modelo' => $exif['IFD0']['Model'] ?? null,
            'software' => $exif['IFD0']['Software'] ?? null,
            'lente' => $exif['EXIF']['LensModel'] ?? $exif['EXIF']['UndefinedTag:0xA434'] ?? null,
        ];

        // Capture settings
        $parsed['captura'] = [
            'data_hora' => $this->parse_datetime($exif['EXIF']['DateTimeOriginal'] ?? $exif['IFD0']['DateTime'] ?? null),
            'exposicao' => $this->format_exposure($exif['EXIF']['ExposureTime'] ?? null),
            'abertura' => $this->format_aperture($exif['EXIF']['FNumber'] ?? null),
            'iso' => $exif['EXIF']['ISOSpeedRatings'] ?? null,
            'distancia_focal' => $this->format_focal_length($exif['EXIF']['FocalLength'] ?? null),
            'distancia_focal_35mm' => isset($exif['EXIF']['FocalLengthIn35mmFilm'])
                ? $exif['EXIF']['FocalLengthIn35mmFilm'] . 'mm'
                : null,
            'modo_exposicao' => $this->get_exposure_mode($exif['EXIF']['ExposureMode'] ?? null),
            'programa_exposicao' => $this->get_exposure_program($exif['EXIF']['ExposureProgram'] ?? null),
            'modo_medicao' => $this->get_metering_mode($exif['EXIF']['MeteringMode'] ?? null),
            'flash' => $this->get_flash_info($exif['EXIF']['Flash'] ?? null),
            'balanco_branco' => $this->get_white_balance($exif['EXIF']['WhiteBalance'] ?? null),
        ];

        // GPS
        if (isset($exif['GPS']) && !empty($exif['GPS'])) {
            $parsed['gps'] = $this->parse_gps_data($exif['GPS']);
        }

        // Copyright and author
        $parsed['autoria'] = [
            'artista' => $exif['IFD0']['Artist'] ?? null,
            'copyright' => $exif['IFD0']['Copyright'] ?? null,
            'descricao' => $exif['IFD0']['ImageDescription'] ?? null,
        ];

        // Remove null values
        $parsed = $this->remove_null_values($parsed);

        return $parsed;
    }

    /**
     * Parse EXIF date/time
     */
    private function parse_datetime(?string $datetime): ?string {
        if (!$datetime) {
            return null;
        }

        try {
            $dt = \DateTime::createFromFormat('Y:m:d H:i:s', $datetime);
            return $dt ? $dt->format('Y-m-d H:i:s') : $datetime;
        } catch (\Exception $e) {
            return $datetime;
        }
    }

    /**
     * Format exposure time
     */
    private function format_exposure(mixed $exposure): ?string {
        if (!$exposure) {
            return null;
        }

        if (is_string($exposure) && strpos($exposure, '/') !== false) {
            list($num, $den) = explode('/', $exposure);
            $value = $num / $den;

            if ($value >= 1) {
                return $value . 's';
            } else {
                return '1/' . round(1 / $value) . 's';
            }
        }

        return $exposure . 's';
    }

    /**
     * Format aperture (f-number)
     */
    private function format_aperture(mixed $fnumber): ?string {
        if (!$fnumber) {
            return null;
        }

        if (is_string($fnumber) && strpos($fnumber, '/') !== false) {
            list($num, $den) = explode('/', $fnumber);
            return 'f/' . round($num / $den, 1);
        }

        return 'f/' . $fnumber;
    }

    /**
     * Format focal length
     */
    private function format_focal_length(mixed $focal): ?string {
        if (!$focal) {
            return null;
        }

        if (is_string($focal) && strpos($focal, '/') !== false) {
            list($num, $den) = explode('/', $focal);
            return round($num / $den) . 'mm';
        }

        return $focal . 'mm';
    }

    /**
     * Orientation text
     */
    private function get_orientation_text(?int $orientation): ?string {
        $orientations = [
            1 => __('Normal', 'tainacan-ai'),
            2 => __('Horizontally flipped', 'tainacan-ai'),
            3 => __('Rotated 180°', 'tainacan-ai'),
            4 => __('Vertically flipped', 'tainacan-ai'),
            5 => __('Horizontally flipped and rotated 270°', 'tainacan-ai'),
            6 => __('Rotated 90°', 'tainacan-ai'),
            7 => __('Horizontally flipped and rotated 90°', 'tainacan-ai'),
            8 => __('Rotated 270°', 'tainacan-ai'),
        ];

        return $orientations[$orientation] ?? null;
    }

    /**
     * Resolution unit
     */
    private function get_resolution_unit(?int $unit): ?string {
        $units = [
            1 => __('No unit', 'tainacan-ai'),
            2 => __('inches', 'tainacan-ai'),
            3 => __('centimeters', 'tainacan-ai'),
        ];

        return $units[$unit] ?? null;
    }

    /**
     * Color space
     */
    private function get_color_space(?int $space): ?string {
        $spaces = [
            1 => 'sRGB',
            65535 => __('Not calibrated', 'tainacan-ai'),
        ];

        return $spaces[$space] ?? null;
    }

    /**
     * Exposure mode
     */
    private function get_exposure_mode(?int $mode): ?string {
        $modes = [
            0 => __('Automatic', 'tainacan-ai'),
            1 => __('Manual', 'tainacan-ai'),
            2 => __('Auto bracket', 'tainacan-ai'),
        ];

        return $modes[$mode] ?? null;
    }

    /**
     * Exposure program
     */
    private function get_exposure_program(?int $program): ?string {
        $programs = [
            0 => __('Not defined', 'tainacan-ai'),
            1 => __('Manual', 'tainacan-ai'),
            2 => __('Normal program', 'tainacan-ai'),
            3 => __('Aperture priority', 'tainacan-ai'),
            4 => __('Shutter priority', 'tainacan-ai'),
            5 => __('Creative', 'tainacan-ai'),
            6 => __('Action', 'tainacan-ai'),
            7 => __('Portrait', 'tainacan-ai'),
            8 => __('Landscape', 'tainacan-ai'),
        ];

        return $programs[$program] ?? null;
    }

    /**
     * Metering mode
     */
    private function get_metering_mode(?int $mode): ?string {
        $modes = [
            0 => __('Unknown', 'tainacan-ai'),
            1 => __('Average', 'tainacan-ai'),
            2 => __('Center weighted', 'tainacan-ai'),
            3 => __('Spot', 'tainacan-ai'),
            4 => __('Multi-spot', 'tainacan-ai'),
            5 => __('Pattern', 'tainacan-ai'),
            6 => __('Partial', 'tainacan-ai'),
        ];

        return $modes[$mode] ?? null;
    }

    /**
     * Flash information
     */
    private function get_flash_info(?int $flash): ?string {
        if ($flash === null) {
            return null;
        }

        $fired = ($flash & 1) ? __('Fired', 'tainacan-ai') : __('Not fired', 'tainacan-ai');

        return $fired;
    }

    /**
     * White balance
     */
    private function get_white_balance(?int $wb): ?string {
        $balances = [
            0 => __('Automatic', 'tainacan-ai'),
            1 => __('Manual', 'tainacan-ai'),
        ];

        return $balances[$wb] ?? null;
    }

    /**
     * Parse GPS data
     */
    private function parse_gps_data(array $gps): array {
        $parsed = [];

        // Latitude
        if (isset($gps['GPSLatitude']) && isset($gps['GPSLatitudeRef'])) {
            $lat = $this->gps_to_decimal($gps['GPSLatitude'], $gps['GPSLatitudeRef']);
            $parsed['latitude'] = $lat;
            $parsed['latitude_formatada'] = $this->format_gps_coordinate($gps['GPSLatitude'], $gps['GPSLatitudeRef'], 'lat');
        }

        // Longitude
        if (isset($gps['GPSLongitude']) && isset($gps['GPSLongitudeRef'])) {
            $lng = $this->gps_to_decimal($gps['GPSLongitude'], $gps['GPSLongitudeRef']);
            $parsed['longitude'] = $lng;
            $parsed['longitude_formatada'] = $this->format_gps_coordinate($gps['GPSLongitude'], $gps['GPSLongitudeRef'], 'lng');
        }

        // Altitude
        if (isset($gps['GPSAltitude'])) {
            $alt = $this->parse_gps_value($gps['GPSAltitude']);
            $ref = $gps['GPSAltitudeRef'] ?? 0;
            $parsed['altitude'] = ($ref == 1 ? -1 : 1) * $alt;
            $parsed['altitude_formatada'] = round($parsed['altitude']) . 'm';
        }

        // Google Maps link
        if (isset($parsed['latitude']) && isset($parsed['longitude'])) {
            $parsed['google_maps_link'] = sprintf(
                'https://www.google.com/maps?q=%s,%s',
                $parsed['latitude'],
                $parsed['longitude']
            );
        }

        return $parsed;
    }

    /**
     * Convert GPS coordinate to decimal
     */
    private function gps_to_decimal(array $coordinate, string $ref): float {
        $degrees = $this->parse_gps_value($coordinate[0]);
        $minutes = $this->parse_gps_value($coordinate[1]);
        $seconds = $this->parse_gps_value($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if ($ref === 'S' || $ref === 'W') {
            $decimal *= -1;
        }

        return round($decimal, 6);
    }

    /**
     * Parse GPS value (fraction)
     */
    private function parse_gps_value(mixed $value): float {
        if (is_string($value) && strpos($value, '/') !== false) {
            list($num, $den) = explode('/', $value);
            return $den != 0 ? $num / $den : 0;
        }

        return (float) $value;
    }

    /**
     * Format GPS coordinate for display
     */
    private function format_gps_coordinate(array $coord, string $ref, string $type): string {
        $degrees = $this->parse_gps_value($coord[0]);
        $minutes = $this->parse_gps_value($coord[1]);
        $seconds = $this->parse_gps_value($coord[2]);

        return sprintf(
            '%d° %d\' %.2f" %s',
            $degrees,
            $minutes,
            $seconds,
            $ref
        );
    }

    /**
     * Remove null values recursively
     */
    private function remove_null_values(array $array): array {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->remove_null_values($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif ($value === null) {
                unset($array[$key]);
            }
        }

        return $array;
    }

    /**
     * Get summary of EXIF data for display
     */
    public function get_summary(array $exif_data): array {
        if (!isset($exif_data['data'])) {
            return [];
        }

        $data = $exif_data['data'];
        $summary = [];

        // Camera
        if (!empty($data['camera']['fabricante']) || !empty($data['camera']['modelo'])) {
            $summary['camera'] = trim(
                ($data['camera']['fabricante'] ?? '') . ' ' . ($data['camera']['modelo'] ?? '')
            );
        }

        // Date
        if (!empty($data['captura']['data_hora'])) {
            $summary['data_captura'] = $data['captura']['data_hora'];
        }

        // Main settings
        $settings = [];
        if (!empty($data['captura']['exposicao'])) {
            $settings[] = $data['captura']['exposicao'];
        }
        if (!empty($data['captura']['abertura'])) {
            $settings[] = $data['captura']['abertura'];
        }
        if (!empty($data['captura']['iso'])) {
            $settings[] = 'ISO ' . $data['captura']['iso'];
        }
        if (!empty($data['captura']['distancia_focal'])) {
            $settings[] = $data['captura']['distancia_focal'];
        }
        if (!empty($settings)) {
            $summary['configuracoes'] = implode(' | ', $settings);
        }

        // Dimensions
        if (!empty($data['imagem']['largura']) && !empty($data['imagem']['altura'])) {
            $summary['dimensoes'] = $data['imagem']['largura'] . ' x ' . $data['imagem']['altura'] . 'px';
        }

        // GPS
        if (!empty($data['gps']['google_maps_link'])) {
            $summary['localizacao'] = $data['gps']['google_maps_link'];
        }

        return $summary;
    }
}
