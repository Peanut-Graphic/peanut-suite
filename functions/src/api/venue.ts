import { Router } from "express";
import { getFirestore, Query } from "firebase-admin/firestore";
import { randomUUID as uuidv4 } from "node:crypto";
import { Timestamp } from "firebase-admin/firestore";
import {
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
} from "../utils/auth";
import {
  SharedVenue,
  CreateVenueRequest,
  UpdateVenueRequest,
  VenueResponse,
  VenueSearchQuery,
} from "../models/SharedVenue";
import { AppSource } from "../models/PeanutAccount";

const router = Router();
const db = getFirestore();

/**
 * GET /api/v1/venue
 * List venues with optional filters
 */
router.get("/", async (req, res) => {
  try {
    const query = req.query as VenueSearchQuery;
    let venuesRef: Query = db.collection("venues")
      .where("is_active", "==", true);

    // Apply filters
    if (query.city) {
      venuesRef = venuesRef.where("city", "==", query.city);
    }
    if (query.state) {
      venuesRef = venuesRef.where("state", "==", query.state);
    }
    if (query.type) {
      venuesRef = venuesRef.where("venue_type", "==", query.type);
    }
    if (query.verified) {
      venuesRef = venuesRef.where("is_verified", "==", true);
    }

    // Limit results
    venuesRef = venuesRef.limit(100);

    const snapshot = await venuesRef.get();
    const venues: SharedVenue[] = [];

    snapshot.forEach((doc) => {
      venues.push(doc.data() as SharedVenue);
    });

    const response: VenueResponse = {
      success: true,
      venues,
    };

    res.json(response);
  } catch (error) {
    console.error("Error listing venues:", error);
    res.status(500).json({
      success: false,
      error: "Failed to list venues",
      error_code: "VENUE_500",
    } as VenueResponse);
  }
});

/**
 * GET /api/v1/venue/search
 * Search venues by name or location
 */
router.get("/search", async (req, res) => {
  try {
    const query = req.query as VenueSearchQuery;
    const searchTerm = query.q?.toLowerCase() || "";

    // For geo search, we'd need a geo-index library
    // For now, do a simple name/city search
    const venuesRef: Query = db.collection("venues")
      .where("is_active", "==", true)
      .limit(50);

    const snapshot = await venuesRef.get();
    const venues: SharedVenue[] = [];

    snapshot.forEach((doc) => {
      const venue = doc.data() as SharedVenue;

      // Client-side text search
      if (searchTerm) {
        const matchesName = venue.name.toLowerCase().includes(searchTerm);
        const matchesCity = venue.city.toLowerCase().includes(searchTerm);
        const matchesAddress = venue.address?.toLowerCase().includes(searchTerm);

        if (!matchesName && !matchesCity && !matchesAddress) {
          return;
        }
      }

      // Distance filter if lat/lng provided
      if (query.lat && query.lng && query.radius_miles) {
        if (venue.latitude && venue.longitude) {
          const distance = haversineDistance(
            query.lat,
            query.lng,
            venue.latitude,
            venue.longitude,
          );
          if (distance > query.radius_miles) {
            return;
          }
        }
      }

      venues.push(venue);
    });

    const response: VenueResponse = {
      success: true,
      venues,
    };

    res.json(response);
  } catch (error) {
    console.error("Error searching venues:", error);
    res.status(500).json({
      success: false,
      error: "Failed to search venues",
      error_code: "VENUE_500",
    } as VenueResponse);
  }
});

/**
 * GET /api/v1/venue/:venueId
 * Get venue details
 */
router.get("/:venueId", async (req, res) => {
  try {
    const { venueId } = req.params;

    const doc = await db.collection("venues").doc(venueId).get();

    if (!doc.exists) {
      res.status(404).json({
        success: false,
        error: "Venue not found",
        error_code: "VENUE_404",
      } as VenueResponse);
      return;
    }

    const response: VenueResponse = {
      success: true,
      venue_id: venueId,
      venue: doc.data() as SharedVenue,
    };

    res.json(response);
  } catch (error) {
    console.error("Error getting venue:", error);
    res.status(500).json({
      success: false,
      error: "Failed to get venue",
      error_code: "VENUE_500",
    } as VenueResponse);
  }
});

/**
 * POST /api/v1/venue
 * Create venue (authenticated)
 */
router.post(
  "/",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const peanutId = req.user!.peanutId!;
      const body = req.body as CreateVenueRequest & { app_source?: AppSource };

      // Validate required fields
      if (!body.name || !body.city || !body.state) {
        res.status(400).json({
          success: false,
          error: "Missing required fields: name, city, state",
          error_code: "VENUE_400",
        } as VenueResponse);
        return;
      }

      // Check for potential duplicate
      const existing = await db.collection("venues")
        .where("name", "==", body.name)
        .where("city", "==", body.city)
        .limit(1)
        .get();

      if (!existing.empty) {
        // Return existing venue instead of creating duplicate
        const existingVenue = existing.docs[0];
        res.status(200).json({
          success: true,
          venue_id: existingVenue.id,
          venue: existingVenue.data() as SharedVenue,
        } as VenueResponse);
        return;
      }

      const venueId = uuidv4();
      const now = Timestamp.now();

      const newVenue: SharedVenue = {
        venue_id: venueId,
        name: body.name,
        venue_type: body.venue_type || "other",
        address: body.address || "",
        city: body.city,
        state: body.state,
        zip: body.zip || "",
        latitude: body.latitude,
        longitude: body.longitude,
        phone: body.phone,
        email: body.email,
        website: body.website,
        has_green_room: false,
        has_sound_system: true,
        has_lighting: false,
        has_mic_stand: true,
        provides_food: false,
        provides_drinks: false,
        provides_lodging: false,
        provides_travel: false,
        created_by_app: body.app_source || "notebook",
        created_by_peanut_id: peanutId,
        is_verified: false,
        is_active: true,
        created_at: now,
        updated_at: now,
      };

      await db.collection("venues").doc(venueId).set(newVenue);

      const response: VenueResponse = {
        success: true,
        venue_id: venueId,
        venue: newVenue,
      };

      res.status(201).json(response);
    } catch (error) {
      console.error("Error creating venue:", error);
      res.status(500).json({
        success: false,
        error: "Failed to create venue",
        error_code: "VENUE_500",
      } as VenueResponse);
    }
  },
);

/**
 * PATCH /api/v1/venue/:venueId
 * Update venue (authenticated, merge strategy)
 */
router.patch(
  "/:venueId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { venueId } = req.params;

      const doc = await db.collection("venues").doc(venueId).get();
      if (!doc.exists) {
        res.status(404).json({
          success: false,
          error: "Venue not found",
          error_code: "VENUE_404",
        } as VenueResponse);
        return;
      }

      const body = req.body as UpdateVenueRequest;
      const updates: Partial<SharedVenue> = {
        updated_at: Timestamp.now(),
      };

      // Copy allowed fields (merge strategy - only update non-null fields)
      const allowedFields: (keyof UpdateVenueRequest)[] = [
        "name", "venue_type", "address", "city", "state", "zip",
        "latitude", "longitude", "phone", "email", "website",
        "booker_name", "booker_email", "booker_phone", "preferred_contact_method",
        "capacity", "stage_type", "has_green_room", "has_sound_system",
        "has_lighting", "has_mic_stand", "typical_pay_range_low",
        "typical_pay_range_high", "pay_type", "provides_food",
        "provides_drinks", "provides_lodging", "provides_travel",
      ];

      for (const field of allowedFields) {
        if (body[field] !== undefined) {
          (updates as Record<string, unknown>)[field] = body[field];
        }
      }

      await db.collection("venues").doc(venueId).update(updates);

      const updatedDoc = await db.collection("venues").doc(venueId).get();

      const response: VenueResponse = {
        success: true,
        venue_id: venueId,
        venue: updatedDoc.data() as SharedVenue,
      };

      res.json(response);
    } catch (error) {
      console.error("Error updating venue:", error);
      res.status(500).json({
        success: false,
        error: "Failed to update venue",
        error_code: "VENUE_500",
      } as VenueResponse);
    }
  },
);

// Helper: Haversine distance calculation
function haversineDistance(
  lat1: number,
  lon1: number,
  lat2: number,
  lon2: number,
): number {
  const R = 3959; // Earth's radius in miles

  const dLat = toRad(lat2 - lat1);
  const dLon = toRad(lon2 - lon1);

  const a =
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
    Math.sin(dLon / 2) * Math.sin(dLon / 2);

  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

  return R * c;
}

function toRad(deg: number): number {
  return deg * (Math.PI / 180);
}

export default router;
