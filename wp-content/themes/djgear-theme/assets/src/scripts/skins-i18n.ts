export type SkinsLocale = 'en' | 'fa';

export interface SkinsCopy {
  stepDevice: string;
  stepVariant: string;
  stepPlan: string;
  stepArtwork: string;
  stepConfirm: string;
  searchDevices: string;
  loadingDevices: string;
  noDevices: string;
  loadingOptions: string;
  noVariants: string;
  noPlans: string;
  outOfStock: string;
  inStock: string;
  basePrice: string;
  surcharge: string;
  included: string;
  leadDays: string;
  uploadPermitted: string;
  uploadDisabled: string;
  proofApprovalRequired: string;
  signInRequiredTitle: string;
  signInRequiredBody: string;
  signInButton: string;
  artworkHeading: string;
  artworkPolicyHint: string;
  chooseFile: string;
  uploading: string;
  uploadButton: string;
  replaceFileButton: string;
  dpiPassTitle: string;
  dpiWarningTitle: string;
  dpiManualTitle: string;
  dpiRejectedTitle: string;
  ackCheckboxLabel: string;
  ackSubmitButton: string;
  ackConfirmedBadge: string;
  notesLabel: string;
  notesPlaceholder: string;
  summaryHeading: string;
  qtyNotice: string;
  addToCartButton: string;
  addingToCart: string;
  addedSuccessTitle: string;
  viewCartButton: string;
  checkoutButton: string;
  errorGeneric: string;
  myJobsTitle: string;
  myJobsEmpty: string;
  loadingJobs: string;
  viewDetails: string;
  hideDetails: string;
  timelineHeading: string;
  revisionsHeading: string;
  downloadFile: string;
  customerArtworkTag: string;
  designerProofTag: string;
  approveProofTitle: string;
  approveProofButton: string;
  requestRevisionButton: string;
  commentPlaceholder: string;
  uploadRevisionTitle: string;
  resumeCartButton: string;
  orderLabel: string;
  trackingLabel: string;
  draftLabel: string;
  statuses: Record<string, string>;
}

const EN_COPY: SkinsCopy = {
  stepDevice: '01 · Select Device',
  stepVariant: '02 · Finish & Color',
  stepPlan: '03 · Ordering Plan',
  stepArtwork: '04 · Artwork & DPI',
  stepConfirm: '05 · Review & Cart',
  searchDevices: 'Filter active DJ models (e.g. DDJ-FLX4, CDJ-3000)...',
  loadingDevices: 'Loading compatible DJ devices...',
  noDevices: 'No active DJ devices are available for custom skins right now.',
  loadingOptions: 'Loading available finishes and plans...',
  noVariants: 'No active skin finishes are currently available for this DJ device.',
  noPlans: 'No active ordering plans are currently configured.',
  outOfStock: 'Out of stock',
  inStock: 'Available',
  basePrice: 'Base price',
  surcharge: 'Plan surcharge',
  included: 'Included',
  leadDays: 'Est. lead time (days)',
  uploadPermitted: 'Custom artwork upload enabled',
  uploadDisabled: 'Ready-made finish — no artwork upload required',
  proofApprovalRequired: 'Includes designer proof review & approval',
  signInRequiredTitle: 'Sign in to configure & upload artwork',
  signInRequiredBody: 'Customer authentication is required to create your skin job, validate custom artwork securely, and add it to your WooCommerce cart.',
  signInButton: 'Sign in to continue',
  artworkHeading: 'Upload custom artwork',
  artworkPolicyHint: 'Accepted formats: PNG, JPEG, single-page TIFF, PDF (max 25 MB). Recommended ≥ 300 DPI; 180–299 DPI requires explicit acknowledgment; < 180 DPI is rejected.',
  chooseFile: 'Select artwork file',
  uploading: 'Uploading & inspecting DPI...',
  uploadButton: 'Upload & Validate DPI',
  replaceFileButton: 'Upload replacement file',
  dpiPassTitle: 'Print-ready (DPI Passed)',
  dpiWarningTitle: 'Medium Resolution — Explicit Acknowledgment Required',
  dpiManualTitle: 'Manual Preflight Review Required',
  dpiRejectedTitle: 'Artwork Rejected (Below 180 DPI or Unsupported)',
  ackCheckboxLabel: 'I understand the resolution/preflight warning for this exact uploaded file and approve proceeding with designer proof review.',
  ackSubmitButton: 'Confirm Acknowledgment',
  ackConfirmedBadge: 'Acknowledgment recorded for current revision',
  notesLabel: 'Order notes (optional)',
  notesPlaceholder: 'Placement notes, logo alignment, or finish instructions...',
  summaryHeading: 'Configuration summary',
  qtyNotice: 'Custom skin configurations are produced individually (quantity locked to 1 per cart line). Final pricing, taxes, and coupons are calculated natively by WooCommerce.',
  addToCartButton: 'Add Custom Skin to Cart',
  addingToCart: 'Adding to WooCommerce Cart...',
  addedSuccessTitle: 'Custom skin added to your WooCommerce cart.',
  viewCartButton: 'View Cart',
  checkoutButton: 'Proceed to Checkout',
  errorGeneric: 'Unable to complete request. Please check your selection and try again.',
  myJobsTitle: 'Your Custom Skin Orders',
  myJobsEmpty: 'You have not configured any custom DJ skins yet.',
  loadingJobs: 'Loading your custom skin orders...',
  viewDetails: 'Details & Timeline',
  hideDetails: 'Hide Details',
  timelineHeading: 'Production Timeline',
  revisionsHeading: 'Artwork & Proof Revisions',
  downloadFile: 'Download',
  customerArtworkTag: 'Customer Artwork',
  designerProofTag: 'Designer Proof',
  approveProofTitle: 'Designer proof is ready for your approval',
  approveProofButton: 'Approve Proof for Production',
  requestRevisionButton: 'Request Revision',
  commentPlaceholder: 'Optional approval note or revision instructions...',
  uploadRevisionTitle: 'Upload Revised Artwork',
  resumeCartButton: 'Add Draft to Cart',
  orderLabel: 'Order',
  trackingLabel: 'Tracking',
  draftLabel: 'Draft (not yet ordered)',
  statuses: {
    draft: 'Draft',
    awaiting_payment: 'Awaiting Payment',
    submitted_for_review: 'Submitted for Review',
    in_design_review: 'In Design Review',
    revision_requested: 'Revision Requested',
    proof_sent: 'Proof Ready for Approval',
    approved_for_production: 'Approved for Production',
    queued_for_production: 'Queued for Production',
    in_production: 'In Production',
    quality_check: 'Quality Check',
    packed: 'Packed',
    shipped: 'Shipped',
    completed: 'Completed',
    on_hold: 'On Hold',
    cancelled: 'Cancelled',
  },
};

const FA_COPY: SkinsCopy = {
  stepDevice: '۰۱ · انتخاب دستگاه',
  stepVariant: '۰۲ · مدل و رنگ',
  stepPlan: '۰۳ · پلن سفارش',
  stepArtwork: '۰۴ · طرح و بررسی DPI',
  stepConfirm: '۰۵ · تأیید و سبد خرید',
  searchDevices: 'جستجوی مدل دستگاه دی‌جی (مثلاً DDJ-FLX4 یا CDJ-3000)...',
  loadingDevices: 'در حال بارگذاری دستگاه‌های سازگار...',
  noDevices: 'در حال حاضر دستگاه فعالی برای سفارش اسکین اختصاصی ثبت نشده است.',
  loadingOptions: 'در حال بارگذاری مدل‌ها و پلن‌های فعال...',
  noVariants: 'مدل یا رنگ فعالی برای این دستگاه در دسترس نیست.',
  noPlans: 'هیچ پلن سفارش فعالی پیکربندی نشده است.',
  outOfStock: 'ناموجود',
  inStock: 'موجود',
  basePrice: 'قیمت پایه',
  surcharge: 'هزینه پلن',
  included: 'شامل قیمت پایه',
  leadDays: 'زمان تقریبی آماده‌سازی (روز)',
  uploadPermitted: 'امکان بارگذاری طرح اختصاصی فعال است',
  uploadDisabled: 'طرح آماده — بدون نیاز به بارگذاری فایل',
  proofApprovalRequired: 'شامل ارسال و تأیید پیش‌نمایش طراح پیش از تولید',
  signInRequiredTitle: 'برای ثبت سفارش و بارگذاری طرح وارد شوید',
  signInRequiredBody: 'برای ایجاد سفارش اسکین، بررسی خودکار کیفیت فایل (DPI) و افزودن به سبد خرید ووکامرس، ورود به حساب کاربری الزامی است.',
  signInButton: 'ورود به حساب کاربری',
  artworkHeading: 'بارگذاری طرح اختصاصی',
  artworkPolicyHint: 'فرمت‌های مجاز: PNG، JPEG، TIFF تک‌صفحه‌ای، PDF (حداکثر ۲۵ مگابایت). کیفیت پیشنهادی ۳۰۰ DPI به بالا؛ ۱۸۰ تا ۲۹۹ DPI نیازمند تأیید صریح؛ کمتر از ۱۸۰ DPI رد می‌شود.',
  chooseFile: 'انتخاب فایل طرح',
  uploading: 'در حال بارگذاری و بررسی DPI...',
  uploadButton: 'بارگذاری و بررسی کیفیت چاپ',
  replaceFileButton: 'جایگزینی فایل طرح',
  dpiPassTitle: 'آماده چاپ (تأیید DPI)',
  dpiWarningTitle: 'کیفیت متوسط — نیازمند تأیید صریح مشتری',
  dpiManualTitle: 'نیازمند بررسی دستی پیش از چاپ',
  dpiRejectedTitle: 'فایل رد شد (کمتر از ۱۸۰ DPI یا نامعتبر)',
  ackCheckboxLabel: 'هشدار کیفیت چاپ برای همین فایل بارگذاری‌شده را مطالعه کردم و ادامه فرآیند با بررسی پیش‌نمایش طراح را تأیید می‌کنم.',
  ackSubmitButton: 'ثبت تأیید صریح',
  ackConfirmedBadge: 'تأیید برای نسخه فعلی فایل ثبت شد',
  notesLabel: 'توضیحات سفارش (اختیاری)',
  notesPlaceholder: 'توضیحات چیدمان لوگو یا جزئیات دلخواه...',
  summaryHeading: 'خلاصه پیکربندی اسکین',
  qtyNotice: 'هر سفارش اسکین اختصاصی به‌صورت مجزا تولید می‌شود (تعداد هر ردیف دقیقاً ۱ است). محاسبه قیمت نهایی، مالیات و کد تخفیف به‌صورت کامل توسط ووکامرس انجام می‌شود.',
  addToCartButton: 'افزودن اسکین اختصاصی به سبد خرید',
  addingToCart: 'در حال افزودن به سبد خرید ووکامرس...',
  addedSuccessTitle: 'اسکین اختصاصی به سبد خرید ووکامرس اضافه شد.',
  viewCartButton: 'مشاهده سبد خرید',
  checkoutButton: 'ادامه جهت تسویه حساب',
  errorGeneric: 'انجام عملیات ممکن نشد. لطفاً انتخاب خود را بررسی و دوباره تلاش کنید.',
  myJobsTitle: 'سفارش‌های اسکین اختصاصی شما',
  myJobsEmpty: 'شما هنوز هیچ اسکین اختصاصی ثبت نکرده‌اید.',
  loadingJobs: 'در حال بارگذاری سفارش‌های اسکین شما...',
  viewDetails: 'جزئیات و زمان‌بندی',
  hideDetails: 'بستن جزئیات',
  timelineHeading: 'روند تولید و وضعیت سفارش',
  revisionsHeading: 'نسخه‌های طرح و پیش‌نمایش طراح',
  downloadFile: 'دانلود فایل',
  customerArtworkTag: 'طرح مشتری',
  designerProofTag: 'پیش‌نمایش طراح',
  approveProofTitle: 'پیش‌نمایش طراح آماده تأیید شماست',
  approveProofButton: 'تأیید پیش‌نمایش برای تولید',
  requestRevisionButton: 'درخواست اصلاح',
  commentPlaceholder: 'یادداشت اختیاری برای تأیید یا اصلاح طرح...',
  uploadRevisionTitle: 'بارگذاری نسخه جدید طرح',
  resumeCartButton: 'افزودن پیش‌نویس به سبد خرید',
  orderLabel: 'سفارش',
  trackingLabel: 'کد رهگیری',
  draftLabel: 'پیش‌نویس (تکمیل‌نشده)',
  statuses: {
    draft: 'پیش‌نویس',
    awaiting_payment: 'در انتظار پرداخت',
    submitted_for_review: 'ارسال‌شده برای بررسی',
    in_design_review: 'در حال بررسی طراحی',
    revision_requested: 'درخواست اصلاح',
    proof_sent: 'پیش‌نمایش آماده تأیید',
    approved_for_production: 'تأییدشده برای تولید',
    queued_for_production: 'در صف تولید',
    in_production: 'در حال تولید',
    quality_check: 'کنترل کیفیت',
    packed: 'بسته‌بندی‌شده',
    shipped: 'ارسال‌شده',
    completed: 'تکمیل‌شده',
    on_hold: 'معلق',
    cancelled: 'لغوشده',
  },
};

export function resolveLocale(dir: string, locale: string): SkinsLocale {
  return dir === 'rtl' || locale.toLowerCase().startsWith('fa') ? 'fa' : 'en';
}

export function getCopy(lang: SkinsLocale): SkinsCopy {
  return lang === 'fa' ? FA_COPY : EN_COPY;
}

export function pickBilingual(lang: SkinsLocale, fa?: string | null, en?: string | null): string {
  if (lang === 'fa') {
    return (fa && fa.trim()) || (en && en.trim()) || '';
  }
  return (en && en.trim()) || (fa && fa.trim()) || '';
}

export function formatNumber(value: number, lang: SkinsLocale): string {
  try {
    return new Intl.NumberFormat(lang === 'fa' ? 'fa-IR' : 'en-US').format(value);
  } catch {
    return String(value);
  }
}
