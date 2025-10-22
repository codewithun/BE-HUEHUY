# Firebase Configuration Setup

## For Production/Real Firebase Authentication

1. **Download Firebase Service Account JSON:**
   - Go to [Firebase Console](https://console.firebase.google.com)
   - Select your project: `huehuy-63c16`
   - Go to Project Settings (gear icon) → Service Accounts
   - Click "Generate new private key"
   - Download the JSON file

2. **Place the file:**
   - Save the downloaded JSON file as `huehuy-63c16.json` in this directory
   - The file should be placed at: `./firebase/huehuy-63c16.json`

3. **Update your .env file:**
   ```bash
   # Uncomment this line in your .env file:
   FIREBASE_CREDENTIALS=./firebase/huehuy-63c16.json
   ```

4. **Security Note:**
   - The `huehuy-63c16.json` file is ignored by git (see `.gitignore`)
   - Never commit this file to version control
   - Each environment (dev, staging, prod) should have its own service account file

## For Development (Current Setup)

Currently, the application is configured to work without Firebase credentials in development:

- Firebase authentication will extract real user data from Firebase tokens when possible
- The system decodes JWT tokens manually to extract user information (email, name, picture)
- Users are created automatically based on the token data
- If token decoding fails, it falls back to a development user
- This is NOT secure for production use as it bypasses token verification

## Files in this directory

- `.gitignore` - Prevents Firebase JSON files from being committed
- `README.md` - This instruction file
- `huehuy-63c16.json.example` - Example structure of the service account file

## Troubleshooting

If you see errors like "Failed to open stream: No such file or directory":
1. Make sure the `huehuy-63c16.json` file exists in this directory
2. Check that `FIREBASE_CREDENTIALS` in `.env` points to the correct path
3. Ensure the file has proper JSON format and valid Firebase credentials

## Development vs Production

**Development (current):**
- No Firebase credentials required
- Real user data extracted from Firebase tokens
- Users are created automatically with their actual Google profile information
- Fallback to mock authentication if token parsing fails

**Production:**
- Real Firebase credentials required
- Real token verification with Google's public keys
- Secure authentication flow