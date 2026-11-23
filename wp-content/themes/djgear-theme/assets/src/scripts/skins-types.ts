export interface ConfiguratorBootConfig {
  deviceId: number;
  locale: string;
  dir: 'rtl' | 'ltr';
  isLoggedIn: boolean;
  loginUrl: string;
  cartUrl: string;
  catalogApi: string;
  skinsApi: string;
  restNonce: string;
}

export interface PublicDeviceItem {
  id: number;
  slug: string;
  model_code: string;
  name_fa: string;
  name_en: string;
  brand?: { name_fa?: string; name_en?: string } | null;
  category?: { name_fa?: string; name_en?: string } | null;
  thumbnail_url?: string | null;
  skin_ready?: boolean;
}

export interface PublicSkinVariant {
  id: number;
  device_id: number;
  wc_product_id: number;
  wc_variation_id: number | null;
  model_code: string;
  model_name_fa: string;
  model_name_en: string;
  color_code: string;
  color_name_fa: string;
  color_name_en: string;
  color_hex: string;
  finish: string;
  preview_image_url: string | null;
  print_width_mm: number | null;
  print_height_mm: number | null;
  base_price: number | null;
  currency: string;
  in_stock: boolean;
  sort_order: number;
}

export interface PublicSkinPlan {
  id: number;
  slug: string;
  name_fa: string;
  name_en: string;
  description_fa: string;
  description_en: string;
  plan_type: string;
  allows_artwork_upload: boolean;
  requires_artwork: boolean;
  requires_proof_approval: boolean;
  max_revisions: number;
  price_surcharge: number;
  estimated_lead_days: number;
  features_fa: string[];
  features_en: string[];
  sort_order: number;
}

export interface DpiPolicy {
  min_dpi: number;
  recommended_dpi: number;
  allowed_formats: string[];
  max_file_mb: number;
}

export interface SkinsOptionsResponse {
  device: {
    device_id: number;
    slug: string;
    model_code: string;
    name_fa: string;
    name_en: string;
  } | null;
  variants: PublicSkinVariant[];
  plans: PublicSkinPlan[];
  dpi_policy: DpiPolicy;
}

export type DpiStatus = 'pass' | 'warning' | 'rejected' | 'manual_review_required' | 'not_applicable';

export interface DpiReport {
  pixel_width?: number;
  pixel_height?: number;
  computed_dpi?: number | null;
  dpi_status: DpiStatus;
  min_required_px_width?: number;
  min_required_px_height?: number;
  recommended_px_width?: number;
  recommended_px_height?: number;
  message_en?: string;
  message_fa?: string;
}

export interface CustomerArtworkRevision {
  id: number;
  revision_type: 'customer_artwork' | 'designer_proof' | string;
  version_number: number;
  original_filename: string;
  mime_type: string;
  file_size_bytes: number;
  sha256_hash: string;
  pixel_width: number | null;
  pixel_height: number | null;
  computed_dpi: number | null;
  dpi_status: DpiStatus;
  low_dpi_acknowledged: boolean;
  customer_decision: string;
  decision_at: string | null;
  note_fa: string | null;
  note_en: string | null;
  created_at: string;
  download_url: string;
}

export interface CustomerStatusHistoryRow {
  id: number;
  from_status: string | null;
  to_status: string;
  actor_role: string;
  comment: string | null;
  created_at: string;
}

export interface CustomerSkinJob {
  job_uuid: string;
  wc_order_id: number | null;
  wc_order_status: string | null;
  wc_order_url: string | null;
  device_id: number;
  skin_variant_id: number;
  plan_id: number;
  snapshot: {
    device?: { name_fa?: string; name_en?: string; model_code?: string };
    variant?: {
      model_name_fa?: string;
      model_name_en?: string;
      color_name_fa?: string;
      color_name_en?: string;
      color_hex?: string;
      finish?: string;
      base_price?: number;
      currency?: string;
    };
    plan?: {
      slug?: string;
      name_fa?: string;
      name_en?: string;
      allows_artwork_upload?: boolean;
      requires_artwork?: boolean;
      requires_proof_approval?: boolean;
      max_revisions?: number;
      price_surcharge?: number;
    };
  };
  status: string;
  current_artwork_id: number | null;
  current_proof_id: number | null;
  approved_proof_id: number | null;
  approved_at: string | null;
  customer_notes: string | null;
  tracking_code: string | null;
  artworks: CustomerArtworkRevision[];
  status_history: CustomerStatusHistoryRow[];
  created_at: string;
  updated_at: string;
}

export interface AddToCartResponse {
  job_uuid: string;
  wc_product_id: number;
  cart_item_key: string | null;
  cart_url: string;
  checkout_url: string;
  job: CustomerSkinJob;
}
