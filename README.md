# Peanut Suite - Firebase Backend

> **Dependency updates:** `renovate.json` extends `local>peanutgraphic/renovate-config`, but Renovate is not installed on the Peanut-Graphic GitHub org, so it has never opened a PR here. `.github/dependabot.yml` provides automated dependency PRs instead — see that file to add/adjust ecosystems.

> Read [Firebase Ownership and End State](docs/FIREBASE-OWNERSHIP.md) before using any Firebase CLI command. Source review and local tests are safe; authentication, deployment, data access, IAM, billing, credential, and recovery operations require fresh project-specific approval.

Shared Firebase backend for the Peanut Suite of comedy industry tools:

- **Notebook** (iOS) - Personal material management for comedians
- **Booker** (WordPress) - Two-sided booking marketplace
- **Festival** (WordPress) - Festival/event production platform

## Architecture

The distributed WordPress plugin requires WordPress 6.0 or newer and PHP 8.1 or newer.

```
peanut-suite/
├── functions/           # Cloud Functions (TypeScript)
│   └── src/
│       ├── api/         # REST API endpoints
│       │   ├── account.ts
│       │   ├── performer.ts
│       │   ├── venue.ts
│       │   └── booking.ts
│       ├── models/      # TypeScript interfaces
│       └── utils/       # Auth middleware
├── firestore.rules      # Security rules
├── storage.rules        # Storage security
└── firebase.json        # Firebase config
```

## API Endpoints

### Account API (`/api/v1/account`)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/create` | Create Peanut Account |
| GET | `/:peanutId` | Get account details |
| PATCH | `/:peanutId` | Update account |
| POST | `/:peanutId/link-booker` | Link Booker profile |
| POST | `/:peanutId/link-festival` | Link Festival role |

### Performer API (`/api/v1/performer`)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List performers (with filters) |
| GET | `/:performerId` | Get public profile |
| GET | `/by-peanut/:peanutId` | Get by Peanut ID |
| POST | `/` | Create profile |
| PATCH | `/:performerId` | Update profile |
| POST | `/:performerId/notebook-stats` | Update Notebook stats |

### Venue API (`/api/v1/venue`)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | List venues (with filters) |
| GET | `/search` | Search by name/location |
| GET | `/:venueId` | Get venue details |
| POST | `/` | Create venue |
| PATCH | `/:venueId` | Update venue |

### Booking API (`/api/v1/booking`)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/for-performer/:peanutId` | Get performer's bookings |
| GET | `/:bookingId` | Get booking details |
| POST | `/` | Create booking (webhook) |
| PATCH | `/:bookingId` | Update status |
| POST | `/:bookingId/notebook-link` | Link to Notebook |

## Setup

### Prerequisites
- Node.js 18+
- Firebase CLI: `npm install -g firebase-tools`
- Firebase project created at console.firebase.google.com

### Installation

```bash
# Clone the repo
cd peanut-suite

# Install dependencies
cd functions && npm install

# Build TypeScript
npm run build

# Login to Firebase
firebase login

# Set project
firebase use peanut-suite
```

### Local Development

```bash
# Start emulators
firebase emulators:start

# Or just functions
npm run serve
```

### Deployment

```bash
# Deploy everything
firebase deploy

# Deploy only functions
firebase deploy --only functions

# Deploy only rules
firebase deploy --only firestore:rules,storage:rules
```

## Firebase Console Setup

1. **Create Project**
   - Go to [Firebase Console](https://console.firebase.google.com)
   - Create project: `peanut-suite`
   - Enable Google Analytics (optional)

2. **Enable Authentication**
   - Go to Authentication > Sign-in method
   - Enable: Email/Password, Apple, Google

3. **Enable Firestore**
   - Go to Firestore Database
   - Create database in production mode
   - Rules will be deployed from `firestore.rules`

4. **Enable Storage**
   - Go to Storage
   - Get started
   - Rules will be deployed from `storage.rules`

5. **Configure Apps**
   - Add iOS app (for Notebook): Bundle ID `com.notebook.app`
   - Add Web app (for Booker/Festival)
   - Download config files

## Environment Variables

For Cloud Functions, set these in Firebase:

```bash
firebase functions:config:set webhook.secret="your-webhook-secret"
```

## Security

- All authenticated endpoints require Firebase ID token
- Webhook endpoints require HMAC-SHA256 signature
- Firestore rules enforce data ownership
- Storage rules limit file sizes and types

## Firestore Collections

| Collection | Description |
|------------|-------------|
| `/accounts/{peanutId}` | Core identity |
| `/accounts_by_uid/{uid}` | UID to Peanut ID mapping |
| `/performers/{performerId}` | Public performer profiles |
| `/venues/{venueId}` | Shared venue database |
| `/bookings/{bookingId}` | Cross-app bookings |
| `/open_mics/{openMicId}` | Community open mic listings |
| `/festivals/{festivalId}` | Festival events |

## License

Private - Peanut Graphic
