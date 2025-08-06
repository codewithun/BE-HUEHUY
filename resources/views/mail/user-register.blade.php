<!DOCTYPE html>
<html>
<head>

  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <title>Verifikasi Akun Pengguna Baru</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style type="text/css">
  @import url('https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,300;1,400;1,500;1,600;1,700;1,800&display=swap');

  html {
	font-size: 14px !important;
	font-weight: 400;
	font-family: 'Open Sans', sans-serif;
  }
  body,
  table,
  td,
  a {
    -ms-text-size-adjust: 100%; /* 1 */
    -webkit-text-size-adjust: 100%; /* 2 */
  }

  /**
   * Remove extra space added to tables and cells in Outlook.
   */
  table,
  td {
    mso-table-rspace: 0pt;
    mso-table-lspace: 0pt;
  }

  /**
   * Better fluid images in Internet Explorer.
   */
  img {
    -ms-interpolation-mode: bicubic;
  }

  /**
   * Remove blue links for iOS devices.
   */
  a[x-apple-data-detectors] {
    font-family: inherit !important;
    font-size: inherit !important;
    font-weight: inherit !important;
    line-height: inherit !important;
    color: inherit !important;
    text-decoration: none !important;
  }

  /**
   * Fix centering issues in Android 4.4.
   */
  div[style*="margin: 16px 0;"] {
    margin: 0 !important;
  }

  body {
    width: 100% !important;
    height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
  }

  /**
   * Collapse table borders to avoid space between cells.
   */
  table {
    border-collapse: collapse !important;
  }

  a {
    color: #5aafff;
  }

  img {
    height: auto;
    line-height: 100%;
    text-decoration: none;
    border: 0;
    outline: none;
  }
  </style>

</head>
<body style="background-color: #e9ecef;">
  <!-- start body -->
  <table border="0" cellpadding="0" cellspacing="0" width="100%">

    <!-- start logo -->
    <tr>
      <td align="center" bgcolor="#e9ecef">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
          <tr>
            <td align="center" valign="top" style="padding: 36px 24px;">
              <a href="https://huehuy.com" target="_blank" style="display: inline-block;">
                {{-- <img src="{{ asset("images/core/logo.png") }}" alt="Logo" border="0" style="display: block; width: 200px; max-width: 200px; min-width: 200px;"> --}}
              </a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- end logo -->

    <!-- start hero -->
    <tr>
      <td align="center" bgcolor="#e9ecef">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
          <tr>
            <td align="left" bgcolor="#ffffff" style="padding: 36px 24px 0; font-family: 'Source Sans Pro', Helvetica, Arial, sans-serif; border-top: 3px solid #5aafff;">
              <h1 style="margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -1px; line-height: 48px;">Verifikasi Akun Baru</h1>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <!-- end hero -->

    <!-- start copy block -->
    <tr>
      <td align="center" bgcolor="#e9ecef">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">

          <!-- start copy -->
          <tr>
            <td align="left" bgcolor="#ffffff" style="padding: 24px; font-family: 'Source Sans Pro', Helvetica, Arial, sans-serif; font-size: 16px; line-height: 24px; color:#666;">
              {{-- <p style="margin: 0;">Klik tombol dibawah ini untuk melanjutkan pendaftaran, disarankan buka email ini menggunakan device yang sama dengan anda ketika melakukan pendaftaran.</p> --}}
              <p style="margin: 0;">Masukkan kode dibawah ini untuk verifikasi akun kamu, jika kode tidak berhasil coba meminta untuk kirim email kembali. Kode hanya bisa digunakan selama 1 jam setelah email terkirim.</p>
            </td>
          </tr>
          <!-- end copy -->

          <!-- start button -->
          <tr>
            <td align="left" bgcolor="#ffffff">
              <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td align="center" bgcolor="#ffffff" style="padding: 12px;">
                    <table border="0" cellpadding="0" cellspacing="0">
                      <tr>
                        <td align="center" style="border-radius: 6px;">
                        {{-- <td align="center" bgcolor="#FFD369" style="border-radius: 6px;"> --}}
                          {{-- <a href="" target="_blank" style="display: inline-block; padding: 16px 36px; font-family: 'Source Sans Pro', Helvetica, Arial, sans-serif; font-size: 16px; color: #ffffff; text-decoration: none; border-radius: 6px;">
                            Konfirmasi & Lanjutkan Pendaftaran  
                          </a> --}}
                          <h1 style="margin: 0; font-size: 28px; font-weight: 700; letter-spacing: 16px; line-height: 48px;">{{ $token }}</h1>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <!-- end button -->

          <!-- start copy -->
          <tr>
            <td align="left" bgcolor="#ffffff" style="padding: 24px; font-family: 'Source Sans Pro', Helvetica, Arial, sans-serif; font-size: 16px; line-height: 24px; color:#666; border-bottom: 3px solid #5aafff">
              <p style="margin: 0;">Demi keamanan, jangan beritahukan kode verifikasi ini kepada siapapun! <br>Abaikan jika kamu tidak merasa melakukan daftar ke Huehuy.</p>
            </td>
          </tr>
          <!-- end copy -->
        </table>
      </td>
    </tr>
    <!-- end copy block -->

    <!-- start footer -->
    <tr>
      <td align="center" bgcolor="#e9ecef" style="padding: 24px;">
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px;">
          <tr>
            <td align="center" bgcolor="#e9ecef" style="padding: 12px 24px; font-family: 'Source Sans Pro', Helvetica, Arial, sans-serif; font-size: 14px; line-height: 20px; color: #666;">
              <p style="margin: 0;">huehuy.com | Huehuy</p>
              {{-- <p style="margin: 0;">Jl. lorem ipsum</p> --}}
            </td>
          </tr>

        </table>
      </td>
    </tr>
    <!-- end footer -->

  </table>
  <!-- end body -->

</body>
</html>