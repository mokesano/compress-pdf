<?php
session_start();

// Aktifkan error reporting untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Fungsi untuk menampilkan waktu mundur sesi
function format_time_left($expiryTime) {
    $timeLeft = $expiryTime - time();
    $minutes = floor($timeLeft / 60);
    $seconds = $timeLeft % 60;
    return sprintf('%02d:%02d', $minutes, $seconds);
}

// Hapus PDF yang kedaluwarsa
$currentTime = time();
if (isset($_SESSION['compressed_pdfs'])) {
    $_SESSION['compressed_pdfs'] = array_filter($_SESSION['compressed_pdfs'], function($pdf) use ($currentTime) {
        return $pdf['expiry'] > $currentTime;
    });
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['pdf'])) {
        $file = $_FILES['pdf'];

        // Validasi ukuran file
        if ($file['size'] > 50 * 1024 * 1024) { // Maksimal 50MB
            $error = 'File size must be less than 50MB.';
        }

        // Validasi tipe file berdasarkan ekstensi
        $allowedExtensions = ['pdf'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedExtensions)) {
            $error = 'Only PDF files are allowed.';
        }

        if (empty($error)) {
            $originalData = file_get_contents($file['tmp_name']);
            $originalSize = strlen($originalData);

            // Temporary file paths
            $tmpOriginalPath = tempnam(sys_get_temp_dir(), 'orig');
            $tmpCompressedPath = tempnam(sys_get_temp_dir(), 'comp');

            // Cek apakah file bisa dibuat
            if (!$tmpOriginalPath || !$tmpCompressedPath || !is_writable(dirname($tmpOriginalPath)) || !is_writable(dirname($tmpCompressedPath))) {
                $error = 'Temporary files could not be created or are not writable.';
            } else {
                // Pindahkan file yang diunggah ke path sementara
                move_uploaded_file($file['tmp_name'], $tmpOriginalPath);

                // Gunakan Ghostscript untuk mengurangi ukuran file PDF
                $cmd = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/prepress -dNOPAUSE -dQUIET -dBATCH -sOutputFile=$tmpCompressedPath $tmpOriginalPath";
                exec($cmd, $output, $returnVar);

                if ($returnVar !== 0) {
                    $error = 'An error occurred while compressing the PDF. Ghostscript returned code: ' . $returnVar;
                } else {
                    $compressedData = file_get_contents($tmpCompressedPath);
                    $compressedSize = strlen($compressedData);

                    // Tambahkan file PDF terkompresi ke session
                    $expiryTime = time() + 300; // 5 menit dari sekarang
                    if (!isset($_SESSION['compressed_pdfs'])) {
                        $_SESSION['compressed_pdfs'] = [];
                    }
                    array_unshift($_SESSION['compressed_pdfs'], [
                        'name' => $file['name'],
                        'original_data' => base64_encode($originalData),
                        'original_size' => $originalSize,
                        'data' => base64_encode($compressedData),
                        'compressed_size' => $compressedSize,
                        'expiry' => $expiryTime
                    ]);

                    // Bersihkan file sementara
                    unlink($tmpOriginalPath);
                    unlink($tmpCompressedPath);

                    // Redirect untuk menghindari resubmission
                    header('Location: compress_pdf.php');
                    exit();
                }
            }
        }
    }
}

// Tampilkan form dan file PDF terkompresi
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compress Your PDF</title>
    <style>
        /* Tambahkan styling di sini */
    </style>
</head>
<body>
    <h1>Compress Your PDF</h1>
    <form method="post" enctype="multipart/form-data">
        <label for="pdf">Choose a PDF to compress (max 50MB):</label>
        <input type="file" id="pdf" name="pdf" required>
        <button type="submit">Compress</button>
    </form>

    <?php if (!empty($error)): ?>
        <p class="error"><?php echo $error; ?></p>
    <?php endif; ?>

    <?php if (!empty($_SESSION['compressed_pdfs'])): ?>
        <table>
            <thead>
                <tr>
                    <th>Original File</th>
                    <th>Compressed Result</th>
                    <th>View Result Compressed</th>
                    <th>Session Time</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($_SESSION['compressed_pdfs'] as $index => $pdf): ?>
                <?php
                $originalPdfSrc = 'data:application/pdf;base64,' . $pdf['original_data'];
                $compressedPdfSrc = 'data:application/pdf;base64,' . $pdf['data'];
                $expiryTime = $pdf['expiry'];
                $originalSizeInKB = round($pdf['original_size'] / 1024, 2) . ' KB';
                $compressedSizeInKB = round($pdf['compressed_size'] / 1024, 2) . ' KB';
                ?>
                <tr id="row-<?php echo $index; ?>">
                    <td>
                        <?php echo $pdf['name']; ?><br><?php echo $originalSizeInKB; ?>
                    </td>
                    <td>
                        sangia_compressed_pdf.pdf<br><?php echo $compressedSizeInKB; ?>
                    </td>
                    <td><a href="<?php echo $compressedPdfSrc; ?>" target="_blank">View</a></td>
                    <td><span id="time-<?php echo $index; ?>"><?php echo format_time_left($expiryTime); ?></span></td>
                    <td>
                        <button type="button" onclick="downloadPdf('<?php echo $compressedPdfSrc; ?>', 'sangia_compressed_pdf.pdf')">Download</button>
                        <button type="button" onclick="deletePdf(<?php echo $index; ?>)">Delete</button>
                    </td>
                </tr>
                <script>
                    (function countdown(index, expiryTime) {
                        var timeLeft = expiryTime - Math.floor(Date.now() / 1000);
                        if (timeLeft > 0) {
                            var minutes = Math.floor(timeLeft / 60);
                            var seconds = timeLeft % 60;
                            document.getElementById('time-' + index).textContent = minutes + ':' + ('0' + seconds).slice(-2);
                            setTimeout(function () {
                                countdown(index, expiryTime);
                            }, 1000);
                        } else {
                            document.getElementById('row-' + index).style.display = 'none';
                        }
                    })(<?php echo $index; ?>, <?php echo $expiryTime; ?>);
                </script>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <script>
        function downloadPdf(uri, filename) {
            var link = document.createElement('a');
            link.href = uri;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function deletePdf(index) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'delete_pdf.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    document.getElementById('row-' + index).style.display = 'none';
                }
            };
            xhr.send('index=' + index);
        }
    </script>
</body>
</html>
