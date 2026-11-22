<?php

declare(strict_types=1);

namespace Richie314\SimpleVirtualFS;

use FPDF;
use GdImage;
use InvalidArgumentException;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/** Creates PDFs and resizes images stored at virtual URIs. */
final class Media
{
    /** Use the supplied mounts and encryption settings for all inputs and outputs. */
    public function __construct(private readonly VirtualFilesystem $files)
    {
    }

    /**
     * Import PDF pages in source order while retaining each page's dimensions.
     *
     * @param non-empty-list<string> $sources Virtual URIs of PDF files in the desired order.
     * @param string $destination Virtual URI of the resulting PDF.
     * @throws InvalidArgumentException If the input list is empty.
     */
    public function mergePdfs(array $sources, string $destination): void
    {
        if ($sources === []) {
            throw new InvalidArgumentException('Provide at least one PDF.');
        }

        $pdf = new Fpdi();
        foreach ($sources as $source) {
            $pages = $pdf->setSourceFile(StreamReader::createByString($this->files->read($source)));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        $this->files->write($destination, $pdf->Output('S'));
    }

    /**
     * Fit JPEG, PNG or WebP images onto one A4 page per input in the supplied order.
     *
     * @param non-empty-list<string> $sources Virtual URIs of images in the desired order.
     * @param string $destination Virtual URI of the resulting PDF.
     * @throws InvalidArgumentException If the list is empty or an image format is unsupported.
     * @throws RuntimeException If an image cannot be decoded or prepared for PDF generation.
     */
    public function imagesToPdf(array $sources, string $destination): void
    {
        if ($sources === []) {
            throw new InvalidArgumentException('Provide at least one image.');
        }

        $pdf = new FPDF();
        foreach ($sources as $source) {
            $content = $this->files->read($source);
            [$image, $type] = $this->decodeImage($content);
            $format = match ($type) {
                IMAGETYPE_JPEG => 'JPEG',
                IMAGETYPE_PNG => 'PNG',
                IMAGETYPE_WEBP => 'PNG',
            };
            if ($type === IMAGETYPE_WEBP) {
                $content = $this->encodeImage($image, IMAGETYPE_PNG, 85);
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $landscape = $width > $height;
            $pdf->AddPage($landscape ? 'L' : 'P', 'A4');
            $scale = min(($pdf->GetPageWidth() - 20) / $width, ($pdf->GetPageHeight() - 20) / $height);
            $width *= $scale;
            $height *= $scale;

            // FPDF consumes the named file immediately.
            // Keep its lifetime to one page.
            $temp = tmpfile();
            if ($temp === false) {
                throw new RuntimeException('Could not create a temporary image file.');
            }
            try {
                if (fwrite($temp, $content) !== strlen($content) || !fflush($temp)) {
                    throw new RuntimeException('Could not prepare the image for PDF generation.');
                }
                $pdf->Image(
                    stream_get_meta_data($temp)['uri'],
                    ($pdf->GetPageWidth() - $width) / 2,
                    ($pdf->GetPageHeight() - $height) / 2,
                    $width,
                    $height,
                    $format,
                );
            } finally {
                fclose($temp);
            }
        }

        $this->files->write($destination, $pdf->Output('S'));
    }

    /**
     * Scale a JPEG, PNG or WebP down to fit both bounds while preserving format and aspect ratio.
     * Images already within the bounds are copied as-is.
     *
     * @param string $source Virtual URI of the input image.
     * @param string $destination Virtual URI of the output image, which may equal the source.
     * @param int $maxWidth Maximum width in pixels, at least 1.
     * @param int $maxHeight Maximum height in pixels, at least 1.
     * @param int $quality JPEG or WebP quality from 0 to 100.
     * @throws InvalidArgumentException If the bounds, quality or image format is invalid.
     */
    public function downscaleImage(
        string $source,
        string $destination,
        int $maxWidth,
        int $maxHeight,
        int $quality = 85,
    ): void {
        if ($maxWidth < 1 || $maxHeight < 1 || $quality < 0 || $quality > 100) {
            throw new InvalidArgumentException('Image bounds must be positive and quality must be between 0 and 100.');
        }

        $content = $this->files->read($source);
        [$image, $type] = $this->decodeImage($content);
        $scale = min(1, $maxWidth / imagesx($image), $maxHeight / imagesy($image));
        if ($scale < 1) {
            $width = max(1, (int) floor(imagesx($image) * $scale));
            $height = max(1, (int) floor(imagesy($image) * $scale));
            $scaled = imagecreatetruecolor($width, $height);
            if ($scaled === false) {
                throw new RuntimeException('Could not allocate the resized image.');
            }

            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            if (!imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image))) {
                throw new RuntimeException('Could not resize the image.');
            }
            $content = $this->encodeImage($scaled, $type, $quality);
        }

        $this->files->write($destination, $content);
    }

    /**
     * Decode a supported image and preserve its alpha channel for subsequent encoding.
     *
     * @return array{GdImage, int} Decoded GD image and its IMAGETYPE_* constant.
     */
    private function decodeImage(#[\SensitiveParameter] string $content): array
    {
        $type = @getimagesizefromstring($content)[2] ?? null;
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Expected a JPEG, PNG or WebP image.');
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            throw new RuntimeException('Could not decode the image with GD.');
        }
        imagesavealpha($image, true);

        return [$image, $type];
    }

    /** Capture the encoded image bytes from GD using the requested format. */
    private function encodeImage(GdImage $image, int $type, int $quality): string
    {
        ob_start();
        try {
            $written = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($image, null, $quality),
                IMAGETYPE_PNG => imagepng($image),
                IMAGETYPE_WEBP => imagewebp($image, null, $quality),
            };
            $content = ob_get_contents();
            if (!$written || $content === false || $content === '') {
                throw new RuntimeException('Could not encode the image.');
            }

            return $content;
        } finally {
            ob_end_clean();
        }
    }
}
