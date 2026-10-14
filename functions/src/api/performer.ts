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
  SharedPerformerProfile,
  CreatePerformerRequest,
  UpdatePerformerRequest,
  NotebookStatsRequest,
  PerformerResponse,
  PerformerSearchQuery,
} from "../models/SharedPerformer";

const router = Router();
const db = getFirestore();

/**
 * GET /api/v1/performer
 * List performers with optional filters
 */
router.get("/", async (req, res) => {
  try {
    const query = req.query as PerformerSearchQuery;
    let performersRef: Query = db.collection("performers")
      .where("is_active", "==", true);

    // Apply filters
    if (query.city) {
      performersRef = performersRef.where("location_city", "==", query.city);
    }
    if (query.state) {
      performersRef = performersRef.where("location_state", "==", query.state);
    }
    if (query.verified) {
      performersRef = performersRef.where("is_verified", "==", true);
    }
    if (query.clean_rating) {
      performersRef = performersRef.where("clean_rating", "==", query.clean_rating);
    }

    // Limit results
    performersRef = performersRef.limit(50);

    const snapshot = await performersRef.get();
    const performers: SharedPerformerProfile[] = [];

    snapshot.forEach((doc) => {
      const data = doc.data() as SharedPerformerProfile;
      // Filter by act_type client-side (Firestore array-contains limitation)
      if (query.act_type && !data.act_types.includes(query.act_type)) {
        return;
      }
      // Filter by rating client-side
      if (query.rating_min && data.average_rating < query.rating_min) {
        return;
      }
      performers.push(data);
    });

    const response: PerformerResponse = {
      success: true,
      performers,
    };

    res.json(response);
  } catch (error) {
    console.error("Error listing performers:", error);
    res.status(500).json({
      success: false,
      error: "Failed to list performers",
      error_code: "PERFORMER_500",
    } as PerformerResponse);
  }
});

/**
 * GET /api/v1/performer/:performerId
 * Get public performer profile
 */
router.get("/:performerId", async (req, res) => {
  try {
    const { performerId } = req.params;

    const doc = await db.collection("performers").doc(performerId).get();

    if (!doc.exists) {
      res.status(404).json({
        success: false,
        error: "Performer not found",
        error_code: "PERFORMER_404",
      } as PerformerResponse);
      return;
    }

    const performer = doc.data() as SharedPerformerProfile;

    // Remove private fields for public access
    const publicPerformer = { ...performer };
    delete publicPerformer.legal_name;
    delete publicPerformer.email;
    delete publicPerformer.phone;

    const response: PerformerResponse = {
      success: true,
      performer_id: performerId,
      performer: publicPerformer,
    };

    res.json(response);
  } catch (error) {
    console.error("Error getting performer:", error);
    res.status(500).json({
      success: false,
      error: "Failed to get performer",
      error_code: "PERFORMER_500",
    } as PerformerResponse);
  }
});

/**
 * GET /api/v1/performer/by-peanut/:peanutId
 * Get performer profile by Peanut ID
 */
router.get("/by-peanut/:peanutId", async (req, res) => {
  try {
    const { peanutId } = req.params;

    const snapshot = await db.collection("performers")
      .where("peanut_id", "==", peanutId)
      .limit(1)
      .get();

    if (snapshot.empty) {
      res.status(404).json({
        success: false,
        error: "Performer not found",
        error_code: "PERFORMER_404",
      } as PerformerResponse);
      return;
    }

    const doc = snapshot.docs[0];
    const performer = doc.data() as SharedPerformerProfile;

    const response: PerformerResponse = {
      success: true,
      performer_id: doc.id,
      performer,
    };

    res.json(response);
  } catch (error) {
    console.error("Error getting performer by peanut ID:", error);
    res.status(500).json({
      success: false,
      error: "Failed to get performer",
      error_code: "PERFORMER_500",
    } as PerformerResponse);
  }
});

/**
 * POST /api/v1/performer
 * Create performer profile (authenticated)
 */
router.post(
  "/",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const peanutId = req.user!.peanutId!;

      // Check if performer profile already exists
      const existing = await db.collection("performers")
        .where("peanut_id", "==", peanutId)
        .limit(1)
        .get();

      if (!existing.empty) {
        res.status(409).json({
          success: false,
          error: "Performer profile already exists",
          error_code: "PERFORMER_EXISTS",
        } as PerformerResponse);
        return;
      }

      const body = req.body as CreatePerformerRequest;

      // Validate required fields
      if (!body.stage_name || !body.bio || !body.location_city || !body.location_state) {
        res.status(400).json({
          success: false,
          error: "Missing required fields",
          error_code: "PERFORMER_400",
        } as PerformerResponse);
        return;
      }

      const performerId = uuidv4();
      const now = Timestamp.now();

      const newPerformer: SharedPerformerProfile = {
        performer_id: performerId,
        peanut_id: peanutId,
        stage_name: body.stage_name,
        bio: body.bio,
        tagline: body.tagline,
        act_types: body.act_types || [],
        clean_rating: body.clean_rating || "adult",
        location_city: body.location_city,
        location_state: body.location_state,
        gallery_urls: [],
        video_urls: [],
        travel_willing: body.travel_willing || false,
        travel_radius_miles: body.travel_radius_miles,
        hourly_rate: body.hourly_rate,
        minimum_fee: body.minimum_fee,
        website_url: body.website_url,
        instagram: body.instagram,
        is_verified: false,
        is_featured: false,
        is_active: true,
        completed_bookings: 0,
        average_rating: 0,
        total_reviews: 0,
        achievement_level: "bronze",
        notebook_linked: false,
        created_at: now,
        updated_at: now,
      };

      await db.collection("performers").doc(performerId).set(newPerformer);

      // Update account with performer ID
      await db.collection("accounts").doc(peanutId).update({
        booker_performer_id: performerId,
        updated_at: now,
      });

      const response: PerformerResponse = {
        success: true,
        performer_id: performerId,
        performer: newPerformer,
      };

      res.status(201).json(response);
    } catch (error) {
      console.error("Error creating performer:", error);
      res.status(500).json({
        success: false,
        error: "Failed to create performer",
        error_code: "PERFORMER_500",
      } as PerformerResponse);
    }
  },
);

/**
 * PATCH /api/v1/performer/:performerId
 * Update performer profile (owner only)
 */
router.patch(
  "/:performerId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { performerId } = req.params;
      const peanutId = req.user!.peanutId!;

      // Verify ownership
      const doc = await db.collection("performers").doc(performerId).get();
      if (!doc.exists) {
        res.status(404).json({
          success: false,
          error: "Performer not found",
          error_code: "PERFORMER_404",
        } as PerformerResponse);
        return;
      }

      const performer = doc.data() as SharedPerformerProfile;
      if (performer.peanut_id !== peanutId) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PERFORMER_403",
        } as PerformerResponse);
        return;
      }

      const body = req.body as UpdatePerformerRequest;
      const updates: Partial<SharedPerformerProfile> = {
        updated_at: Timestamp.now(),
      };

      // Copy allowed fields
      const allowedFields: (keyof UpdatePerformerRequest)[] = [
        "stage_name", "bio", "tagline", "act_types", "clean_rating",
        "profile_photo_url", "gallery_urls", "video_urls",
        "website_url", "instagram", "twitter", "tiktok", "youtube",
        "hourly_rate", "minimum_fee", "travel_willing", "travel_radius_miles",
        "location_city", "location_state", "is_active",
      ];

      for (const field of allowedFields) {
        if (body[field] !== undefined) {
          (updates as Record<string, unknown>)[field] = body[field];
        }
      }

      await db.collection("performers").doc(performerId).update(updates);

      const updatedDoc = await db.collection("performers").doc(performerId).get();

      const response: PerformerResponse = {
        success: true,
        performer_id: performerId,
        performer: updatedDoc.data() as SharedPerformerProfile,
      };

      res.json(response);
    } catch (error) {
      console.error("Error updating performer:", error);
      res.status(500).json({
        success: false,
        error: "Failed to update performer",
        error_code: "PERFORMER_500",
      } as PerformerResponse);
    }
  },
);

/**
 * POST /api/v1/performer/:performerId/notebook-stats
 * Update performer with Notebook statistics (owner only)
 */
router.post(
  "/:performerId/notebook-stats",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { performerId } = req.params;
      const peanutId = req.user!.peanutId!;

      // Verify ownership
      const doc = await db.collection("performers").doc(performerId).get();
      if (!doc.exists) {
        res.status(404).json({
          success: false,
          error: "Performer not found",
          error_code: "PERFORMER_404",
        } as PerformerResponse);
        return;
      }

      const performer = doc.data() as SharedPerformerProfile;
      if (performer.peanut_id !== peanutId) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "PERFORMER_403",
        } as PerformerResponse);
        return;
      }

      const body = req.body as NotebookStatsRequest;

      const updates: Partial<SharedPerformerProfile> = {
        notebook_linked: true,
        total_shows_logged: body.total_shows,
        years_performing: body.years_performing,
        home_venues: body.home_venues.slice(0, 5), // Limit to 5
        updated_at: Timestamp.now(),
      };

      await db.collection("performers").doc(performerId).update(updates);

      const response: PerformerResponse = {
        success: true,
        performer_id: performerId,
      };

      res.json(response);
    } catch (error) {
      console.error("Error updating notebook stats:", error);
      res.status(500).json({
        success: false,
        error: "Failed to update notebook stats",
        error_code: "PERFORMER_500",
      } as PerformerResponse);
    }
  },
);

export default router;
