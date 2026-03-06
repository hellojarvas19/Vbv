# VBV Checker API

Simple API to check VBV/3DS status for credit cards.

## Endpoint

```
GET /api.php?cc={card}|{mm}|{yy}|{cvv}
```

## Example

```bash
curl "https://your-app.railway.app/api.php?cc=4111111111111111|12|25|123"
```

## Response

```json
{
  "vbv": "challenge_required"
}
```

## Deploy to Railway

1. Push this repo to GitHub
2. Connect to Railway
3. Deploy automatically

## Status Values

- `challenge_required` - 3DS authentication required
- `authenticate_successful` - Card authenticated
- `lookup_enrolled` - Card enrolled in 3DS
- `lookup_not_enrolled` - Card not enrolled
- `lookup_error` - Lookup failed
- `unknown` - Status unknown
