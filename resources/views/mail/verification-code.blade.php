<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi Email</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .verification-code {
            background: #3498db;
            color: white;
            font-size: 32px;
            font-weight: bold;
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            letter-spacing: 8px;
            margin: 30px 0;
            font-family: 'Courier New', monospace;
        }
        .info {
            background: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            color: #7f8c8d;
            font-size: 14px;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">{{ $appName }}</div>
            <h1>Verifikasi Email Anda</h1>
        </div>

        <p>Halo,</p>
        
        <p>Kami menerima permintaan untuk memverifikasi alamat email Anda. Gunakan kode verifikasi berikut:</p>

        <div class="verification-code">
            {{ $code }}
        </div>

        <div class="info">
            <strong>Informasi Penting:</strong>
            <ul>
                <li>Kode ini akan kedaluwarsa dalam <strong>10 menit</strong></li>
                <li>Kode hanya dapat digunakan <strong>satu kali</strong></li>
                <li>Jangan bagikan kode ini kepada siapa pun</li>
            </ul>
        </div>

        <div class="warning">
            <strong>⚠️ Peringatan Keamanan:</strong><br>
            Jika Anda tidak meminta kode verifikasi ini, abaikan email ini. Akun Anda tetap aman.
        </div>

        <p>Jika Anda mengalami masalah, silakan hubungi tim dukungan kami.</p>

        <div class="footer">
            <p>Email ini dikirim secara otomatis, mohon jangan membalas.</p>
            <p>&copy; {{ date('Y') }} {{ $appName }}. Semua hak dilindungi.</p>
        </div>
    </div>
</body>
</html>
