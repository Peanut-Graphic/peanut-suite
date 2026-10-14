import { Router } from "express";
import { getFirestore, Query } from "firebase-admin/firestore";
import { randomUUID as uuidv4 } from "node:crypto";
import { Timestamp } from "firebase-admin/firestore";
import {
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  verifyWebhookSignature,
} from "../utils/auth";
import {
  SharedBooking,
  CreateBookingRequest,
  UpdateBookingRequest,
  LinkNotebookRequest,
  BookingResponse,
  BookingSearchQuery,
} from "../models/SharedBooking";

const router = Router();
const db = getFirestore();

// Webhook secret for Booker/Festival (should be in env)
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || "";

/**
 * GET /api/v1/booking/for-performer/:peanutId
 * Get all bookings for a performer (authenticated, owner only)
 */
router.get(
  "/for-performer/:peanutId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { peanutId } = req.params;
      const query = req.query as BookingSearchQuery;

      // Verify ownership
      if (req.user!.peanutId !== peanutId) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "BOOKING_403",
        } as BookingResponse);
        return;
      }

      let bookingsRef: Query = db.collection("bookings")
        .where("performer_peanut_id", "==", peanutId);

      // Apply filters
      if (query.status) {
        bookingsRef = bookingsRef.where("booking_status", "==", query.status);
      }
      if (query.source) {
        bookingsRef = bookingsRef.where("source", "==", query.source);
      }
      if (query.notebook_imported !== undefined) {
        const importedValue = String(query.notebook_imported) === "true";
        bookingsRef = bookingsRef.where("notebook_imported", "==", importedValue);
      }

      // Order by date
      bookingsRef = bookingsRef.orderBy("event_date", "desc").limit(100);

      const snapshot = await bookingsRef.get();
      const bookings: SharedBooking[] = [];

      snapshot.forEach((doc) => {
        const booking = doc.data() as SharedBooking;

        // Date filters (client-side)
        if (query.from_date) {
          const fromDate = new Date(query.from_date);
          if (booking.event_date.toDate() < fromDate) {
            return;
          }
        }
        if (query.to_date) {
          const toDate = new Date(query.to_date);
          if (booking.event_date.toDate() > toDate) {
            return;
          }
        }

        bookings.push(booking);
      });

      const response: BookingResponse = {
        success: true,
        bookings,
      };

      res.json(response);
    } catch (error) {
      console.error("Error getting bookings:", error);
      res.status(500).json({
        success: false,
        error: "Failed to get bookings",
        error_code: "BOOKING_500",
      } as BookingResponse);
    }
  },
);

/**
 * GET /api/v1/booking/:bookingId
 * Get booking details (authenticated, involved parties only)
 */
router.get(
  "/:bookingId",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { bookingId } = req.params;
      const peanutId = req.user!.peanutId!;

      const doc = await db.collection("bookings").doc(bookingId).get();

      if (!doc.exists) {
        res.status(404).json({
          success: false,
          error: "Booking not found",
          error_code: "BOOKING_404",
        } as BookingResponse);
        return;
      }

      const booking = doc.data() as SharedBooking;

      // Verify access (performer or customer)
      if (
        booking.performer_peanut_id !== peanutId &&
        booking.customer_peanut_id !== peanutId
      ) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "BOOKING_403",
        } as BookingResponse);
        return;
      }

      const response: BookingResponse = {
        success: true,
        booking_id: bookingId,
        booking,
      };

      res.json(response);
    } catch (error) {
      console.error("Error getting booking:", error);
      res.status(500).json({
        success: false,
        error: "Failed to get booking",
        error_code: "BOOKING_500",
      } as BookingResponse);
    }
  },
);

/**
 * POST /api/v1/booking
 * Create booking (webhook from Booker or Festival)
 */
router.post("/", async (req, res) => {
  try {
    // Verify webhook signature
    const signature = req.headers["x-peanut-signature"] as string;
    const payload = JSON.stringify(req.body);

    if (!signature || !verifyWebhookSignature(payload, signature, WEBHOOK_SECRET)) {
      res.status(401).json({
        success: false,
        error: "Invalid webhook signature",
        error_code: "BOOKING_401",
      } as BookingResponse);
      return;
    }

    const body = req.body as CreateBookingRequest;

    // Validate required fields
    if (!body.performer_peanut_id || !body.event_title || !body.event_date) {
      res.status(400).json({
        success: false,
        error: "Missing required fields",
        error_code: "BOOKING_400",
      } as BookingResponse);
      return;
    }

    const bookingId = uuidv4();
    const now = Timestamp.now();

    const newBooking: SharedBooking = {
      booking_id: bookingId,
      booking_number: body.booking_number,
      source: body.source,
      source_event_id: body.source_event_id,
      performer_peanut_id: body.performer_peanut_id,
      customer_peanut_id: body.customer_peanut_id,
      festival_id: body.festival_id,
      event_title: body.event_title,
      event_date: Timestamp.fromDate(new Date(body.event_date)),
      event_start_time: body.event_start_time,
      event_end_time: body.event_end_time,
      set_length_minutes: body.set_length_minutes,
      venue_id: body.venue_id,
      venue_name: body.venue_name,
      venue_address: body.venue_address,
      venue_city: body.venue_city,
      venue_state: body.venue_state,
      show_type: body.show_type,
      slot_position: body.slot_position,
      slot_type: body.slot_type,
      payment_amount: body.payment_amount,
      payment_status: "pending",
      booking_status: "pending",
      performer_confirmed: false,
      notebook_imported: false,
      created_at: now,
      updated_at: now,
    };

    await db.collection("bookings").doc(bookingId).set(newBooking);

    const response: BookingResponse = {
      success: true,
      booking_id: bookingId,
      booking: newBooking,
    };

    res.status(201).json(response);
  } catch (error) {
    console.error("Error creating booking:", error);
    res.status(500).json({
      success: false,
      error: "Failed to create booking",
      error_code: "BOOKING_500",
    } as BookingResponse);
  }
});

/**
 * PATCH /api/v1/booking/:bookingId
 * Update booking status (webhook or authenticated)
 */
router.patch("/:bookingId", async (req, res) => {
  try {
    const { bookingId } = req.params;

    // Check webhook signature first
    const signature = req.headers["x-peanut-signature"] as string;
    const payload = JSON.stringify(req.body);
    const isWebhook = signature && verifyWebhookSignature(payload, signature, WEBHOOK_SECRET);

    // If not webhook, require auth
    if (!isWebhook) {
      let authenticated = false;
      await authenticateToken(req, res, () => { authenticated = true; });
      if (!authenticated) return;
      let accountLoaded = false;
      await loadPeanutAccount(req, res, () => { accountLoaded = true; });
      if (!accountLoaded) return;
      let accountRequired = false;
      requirePeanutAccount(req, res, () => { accountRequired = true; });
      if (!accountRequired) return;
    }

    const doc = await db.collection("bookings").doc(bookingId).get();
    if (!doc.exists) {
      res.status(404).json({
        success: false,
        error: "Booking not found",
        error_code: "BOOKING_404",
      } as BookingResponse);
      return;
    }

    const booking = doc.data() as SharedBooking;
    if (!isWebhook && booking.performer_peanut_id !== req.user?.peanutId && booking.customer_peanut_id !== req.user?.peanutId) {
      res.status(403).json({ success: false, error: "Permission denied", error_code: "BOOKING_403" } as BookingResponse);
      return;
    }
    const body = req.body as UpdateBookingRequest;
    const updates: Partial<SharedBooking> = {
      updated_at: Timestamp.now(),
    };

    // Copy allowed fields
    if (body.booking_status !== undefined) {
      updates.booking_status = body.booking_status;
    }
    if (body.payment_status !== undefined) {
      updates.payment_status = body.payment_status;
    }
    if (body.performer_confirmed !== undefined) {
      updates.performer_confirmed = body.performer_confirmed;
    }
    if (body.set_length_minutes !== undefined) {
      updates.set_length_minutes = body.set_length_minutes;
    }
    if (body.slot_position !== undefined) {
      updates.slot_position = body.slot_position;
    }
    if (body.slot_type !== undefined) {
      updates.slot_type = body.slot_type;
    }

    await db.collection("bookings").doc(bookingId).update(updates);

    const updatedDoc = await db.collection("bookings").doc(bookingId).get();

    const response: BookingResponse = {
      success: true,
      booking_id: bookingId,
      booking: updatedDoc.data() as SharedBooking,
    };

    res.json(response);
  } catch (error) {
    console.error("Error updating booking:", error);
    res.status(500).json({
      success: false,
      error: "Failed to update booking",
      error_code: "BOOKING_500",
    } as BookingResponse);
  }
});

/**
 * POST /api/v1/booking/:bookingId/notebook-link
 * Link booking to Notebook show (authenticated performer only)
 */
router.post(
  "/:bookingId/notebook-link",
  authenticateToken,
  loadPeanutAccount,
  requirePeanutAccount,
  async (req, res) => {
    try {
      const { bookingId } = req.params;
      const peanutId = req.user!.peanutId!;

      const doc = await db.collection("bookings").doc(bookingId).get();
      if (!doc.exists) {
        res.status(404).json({
          success: false,
          error: "Booking not found",
          error_code: "BOOKING_404",
        } as BookingResponse);
        return;
      }

      const booking = doc.data() as SharedBooking;

      // Verify performer ownership
      if (booking.performer_peanut_id !== peanutId) {
        res.status(403).json({
          success: false,
          error: "Permission denied",
          error_code: "BOOKING_403",
        } as BookingResponse);
        return;
      }

      // Check if already imported
      if (booking.notebook_imported) {
        res.status(409).json({
          success: false,
          error: "Booking already imported",
          error_code: "BOOKING_IMPORTED",
        } as BookingResponse);
        return;
      }

      const body = req.body as LinkNotebookRequest;

      if (!body.notebook_show_id) {
        res.status(400).json({
          success: false,
          error: "Missing notebook_show_id",
          error_code: "BOOKING_400",
        } as BookingResponse);
        return;
      }

      await db.collection("bookings").doc(bookingId).update({
        notebook_imported: true,
        notebook_show_id: body.notebook_show_id,
        updated_at: Timestamp.now(),
      });

      const response: BookingResponse = {
        success: true,
        booking_id: bookingId,
      };

      res.json(response);
    } catch (error) {
      console.error("Error linking notebook:", error);
      res.status(500).json({
        success: false,
        error: "Failed to link notebook",
        error_code: "BOOKING_500",
      } as BookingResponse);
    }
  },
);

export default router;
