<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * qwoo's own texts in the store's language (Qwoo_Store_Language): messages
 * the storefront shows (qwoo/v1 answers), emails to customers and the owner,
 * and the content a new store starts with. WordPress' and WooCommerce's own
 * texts come from their language packs.
 *
 * Texts are written in English and looked up by that English text. Messages
 * with a name or number in them use {name} placeholders:
 *   Qwoo_I18n::t( 'Hi {name},', [ 'name' => $first ] )
 * The REST filter translates whole English messages, also ones built with
 * values ("{n}" matches anything).
 */
class Qwoo_I18n {

    public static function init() {
        add_filter( 'rest_post_dispatch', [ __CLASS__, 'translate_rest' ], 20, 3 );
    }

    /** A language set for a while (publishing an extra language's files), else null. */
    private static $forced = null;

    /** Runs $fn with texts in $code (an extra language's files are written in it). */
    public static function in_language( $code, callable $fn ) {
        $previous     = self::$forced;
        self::$forced = $code;
        try {
            return $fn();
        } finally {
            self::$forced = $previous;
        }
    }

    /**
     * The language texts are in by default: a language being published, else
     * the storefront request's (X-Qwoo-Lang, an extra language), else the store's.
     */
    public static function default_code() {
        if ( self::$forced !== null ) {
            return self::$forced;
        }
        if ( ! class_exists( 'Qwoo_Store_Language' ) ) {
            return 'en';
        }
        $lang = Qwoo_Store_Language::request_lang();
        return $lang !== '' ? $lang : Qwoo_Store_Language::get();
    }

    /** $code: a language ('en', 'he'), default the store's. */
    public static function t( $text, $params = [], $code = null ) {
        // Back-compat: t( $text, 'he' ).
        if ( is_string( $params ) ) {
            $code   = $params;
            $params = [];
        }
        $code = $code ?? self::default_code();
        $out  = $code === 'he' && isset( self::HE[ $text ] ) ? self::HE[ $text ] : $text;
        foreach ( (array) $params as $key => $value ) {
            $out = str_replace( '{' . $key . '}', (string) $value, $out );
        }
        return $out;
    }

    /** An English message, also one with values in it, in the store's language. */
    public static function message( $text ) {
        $text = (string) $text;
        if ( self::default_code() !== 'he' || $text === '' ) {
            return $text;
        }
        if ( isset( self::HE[ $text ] ) ) {
            return self::HE[ $text ];
        }
        static $patterns = null;
        if ( $patterns === null ) {
            $patterns = [];
            foreach ( self::HE as $key => $out ) {
                if ( strpos( $key, '{' ) === false ) {
                    continue;
                }
                $names = [];
                $re    = preg_replace_callback( '/\\\\\{(\w+)\\\\\}/', static function ( $m ) use ( &$names ) {
                    $names[] = $m[1];
                    return '(.+?)';
                }, preg_quote( $key, '/' ) );
                $patterns[] = [ '/^' . $re . '$/s', $names, $out ];
            }
        }
        foreach ( $patterns as [ $re, $names, $out ] ) {
            if ( preg_match( $re, $text, $m ) ) {
                foreach ( $names as $i => $name ) {
                    $out = str_replace( '{' . $name . '}', $m[ $i + 1 ], $out );
                }
                return $out;
            }
        }
        return $text;
    }

    /** qwoo/v1 answers: their "message" (errors and notices) in the store's language. */
    public static function translate_rest( $response, $server, $request ) {
        if ( strpos( (string) $request->get_route(), '/qwoo/v1/' ) !== 0 || ! ( $response instanceof WP_REST_Response ) ) {
            return $response;
        }
        // The dashboard's routes answer the platform, which translates by itself.
        if ( strpos( (string) $request->get_route(), '/qwoo/v1/platform' ) === 0 ) {
            return $response;
        }
        $data = $response->get_data();
        if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
            $data['message'] = self::message( $data['message'] );
            $response->set_data( $data );
        }
        return $response;
    }

    const HE = [
        // Answers to the storefront.
        'Authentication required.'                                                                  => 'צריך להתחבר.',
        'Choose from 1 to 5 stars.'                                                                 => 'יש לבחור בין כוכב אחד לחמישה.',
        'Failed to update profile. Please try again.'                                               => 'עדכון הפרופיל נכשל. כדאי לנסות שוב.',
        'If an account matches that username or email, we\'ve sent a password reset link to it.'    => 'אם יש חשבון עם שם המשתמש או האימייל האלה, שלחנו אליו קישור לאיפוס הסיסמה.',
        'Incorrect password. Please try again.'                                                     => 'הסיסמה שגויה. כדאי לנסות שוב.',
        'Incorrect username or password.'                                                           => 'שם המשתמש או הסיסמה שגויים.',
        'Invalid product ID.'                                                                       => 'מוצר לא תקין.',
        'Login failed. Please check your credentials and try again.'                                => 'ההתחברות נכשלה. כדאי לבדוק את הפרטים ולנסות שוב.',
        'No account found with that email address.'                                                 => 'לא נמצא חשבון עם כתובת האימייל הזו.',
        'No account found with that username or email.'                                             => 'לא נמצא חשבון עם שם המשתמש או האימייל האלה.',
        'Our store has moved. To sign in, set a new password: we\'ve emailed you a link.'               => 'החנות שלנו עברה. כדי להתחבר יש לבחור סיסמה חדשה: שלחנו אליך קישור באימייל.',
        'No active session.'                                                                        => 'אין התחברות פעילה.',
        'No fields provided to update.'                                                             => 'לא נשלחו שדות לעדכון.',
        'Only customers who bought this product can review it.'                                     => 'רק לקוחות שקנו את המוצר יכולים לכתוב עליו ביקורת.',
        'Please check "{n}".'                                                                       => 'יש לבדוק את "{n}".',
        'Please choose a valid option for "{n}".'                                                   => 'יש לבחור אפשרות תקינה עבור "{n}".',
        'Please choose at least one option for "{n}".'                                              => 'יש לבחור לפחות אפשרות אחת עבור "{n}".',
        'Please enter a valid date.'                                                                => 'יש להזין תאריך תקין.',
        'Please enter a valid email address.'                                                       => 'יש להזין כתובת אימייל תקינה.',
        'Please enter a valid phone number.'                                                        => 'יש להזין מספר טלפון תקין.',
        'Please enter a valid URL.'                                                                 => 'יש להזין כתובת אינטרנט תקינה.',
        'Please enter your password.'                                                               => 'יש להזין סיסמה.',
        'Please enter your username or email.'                                                      => 'יש להזין שם משתמש או אימייל.',
        'Please fix the highlighted fields.'                                                        => 'יש לתקן את השדות המסומנים.',
        'Please tick the box to agree to the store\'s terms before placing your order.'             => 'יש לסמן את התיבה כדי להסכים לתנאי החנות לפני ביצוע ההזמנה.',
        '"{n}" is required.'                                                                        => '"{n}" הוא שדה חובה.',
        '"{n}" must be a number.'                                                                   => '"{n}" חייב להיות מספר.',
        'Reviews are turned off in this store.'                                                     => 'הביקורות כבויות בחנות הזו.',
        'Reviews are written on the store\'s product pages.'                                        => 'ביקורות כותבים בעמודי המוצרים בחנות.',
        'Sign in to write a review.'                                                                => 'צריך להתחבר כדי לכתוב ביקורת.',
        'Thanks!'                                                                                   => 'תודה!',
        'Thanks! We got your message.'                                                              => 'תודה! ההודעה התקבלה.',
        'Thanks! Your review will show once the store approves it.'                                 => 'תודה! הביקורת תופיע אחרי שהחנות תאשר אותה.',
        'The review couldn\'t be saved. Try again.'                                                 => 'לא הצלחנו לשמור את הביקורת. כדאי לנסות שוב.',
        'The title can be up to {n} characters.'                                                    => 'הכותרת יכולה להיות עד {n} תווים.',
        'This account can\'t be deleted here. Contact the store.'                                   => 'אי אפשר למחוק את החשבון כאן. כדאי לפנות לחנות.',
        'This blog category doesn\'t exist.'                                                        => 'קטגוריית הבלוג הזו לא קיימת.',
        'This field'                                                                                => 'השדה הזה',
        'This form is no longer available.'                                                         => 'הטופס הזה כבר לא זמין.',
        'This isn\'t available right now. Contact the store.'                                       => 'זה לא זמין כרגע. כדאי לפנות לחנות.',
        'This link isn\'t valid any more. Ask for a new one from My account.'                       => 'הקישור כבר לא תקף. אפשר לבקש חדש מהחשבון שלי.',
        'This post doesn\'t exist.'                                                                 => 'הפוסט הזה לא קיים.',
        'This product isn\'t available.'                                                            => 'המוצר הזה לא זמין.',
        'This review link isn\'t valid any more.'                                                   => 'קישור הביקורת כבר לא תקף.',
        'You have an order that is still being processed. Once it\'s done, you can delete your account (or contact the store).' => 'יש לך הזמנה שעדיין בטיפול. כשהיא תסתיים, אפשר יהיה למחוק את החשבון (או לפנות לחנות).',
        'You\'ve already reviewed this product. Thank you!'                                         => 'כבר כתבת ביקורת על המוצר הזה. תודה!',
        'Write a few words (up to {n} characters).'                                                 => 'כמה מילים (עד {n} תווים).',
        'Too many requests. Please try again later.'                                                => 'יותר מדי בקשות. כדאי לנסות שוב מאוחר יותר.',
        'Password must be at least 8 characters.'                                                   => 'הסיסמה חייבת להכיל לפחות 8 תווים.',
        'Password reset successfully.'                                                              => 'הסיסמה אופסה.',
        'Invalid or expired reset link.'                                                            => 'קישור האיפוס לא תקין או שפג תוקפו.',

        // Emails to customers.
        'Hi {name},'                                                                                => 'היי {name},',
        'Hi,'                                                                                       => 'היי,',
        'Thank you for your order from {store}. We\'d love to hear what you think. It only takes a minute:' => 'תודה על ההזמנה מ־{store}. נשמח לשמוע מה דעתך. זה לוקח רק דקה:',
        'Review {name}'                                                                             => 'ביקורת על {name}',
        'You\'re getting this one-time email because you ordered from us.'                          => 'קיבלת את האימייל החד־פעמי הזה כי הזמנת אצלנו.',
        'How was your order from {store}?'                                                          => 'איך הייתה ההזמנה מ־{store}?',
        'How was your order?'                                                                       => 'איך הייתה ההזמנה?',
        'Someone (hopefully you) asked to delete your account at {store}.'                          => 'התקבלה בקשה (כנראה ממך) למחוק את החשבון שלך ב־{store}.',
        'Deleting removes your account, your saved details and your name, email, phone and addresses from your past orders. It can\'t be undone.' => 'המחיקה מסירה את החשבון, את הפרטים השמורים ואת השם, האימייל, הטלפון והכתובות מההזמנות הקודמות. אי אפשר לבטל אותה.',
        'Delete my account'                                                                         => 'מחיקת החשבון שלי',
        'The link works for one hour. If you didn\'t ask for this, ignore this email: your account stays as it is.' => 'הקישור תקף לשעה. אם לא ביקשת את זה, אפשר להתעלם מהאימייל: החשבון נשאר כמו שהוא.',
        'Confirm deleting your account at {store}'                                                  => 'אישור מחיקת החשבון שלך ב־{store}',
        'Delete your account?'                                                                      => 'למחוק את החשבון?',

        // Emails to the owner.
        'A customer deleted their account'                                                          => 'לקוח מחק את החשבון שלו',
        "A customer deleted their account from your store, as privacy laws allow.\n\nTheir name, email, phone and addresses were removed from their orders ({n}). The amounts stay in your sales reports." => "לקוח מחק את החשבון שלו מהחנות, כפי שחוקי הפרטיות מאפשרים.\n\nהשם, האימייל, הטלפון והכתובות שלו הוסרו מההזמנות שלו ({n}). הסכומים נשארים בדוחות המכירות.",
        'New review waiting for approval: {name}'                                                   => 'ביקורת חדשה ממתינה לאישור: {name}',
        '{name} reviewed {product}: {stars}'                                                        => '{name} כתב/ה ביקורת על {product}: {stars}',
        'A customer'                                                                                => 'לקוח',
        'Approve or hide it in your dashboard: {url}'                                               => 'אפשר לאשר או להסתיר אותה בלוח הבקרה: {url}',
        'Approve or hide it in your dashboard.'                                                     => 'אפשר לאשר או להסתיר אותה בלוח הבקרה.',
        'It shows on your store only after you approve it.'                                         => 'היא תופיע בחנות רק אחרי שתאשר/י אותה.',
        'New form submission'                                                                       => 'פנייה חדשה מטופס',
        '— Sent from the "{page}" page form.'                                                       => '— נשלח מהטופס בעמוד "{page}".',
        '{n} is now on sale for {n}'                                                                => '{n} עכשיו במבצע ב־{n}',

        // A new store's content.
        'Home'                                                                                      => 'בית',
        'Products'                                                                                  => 'מוצרים',
        'Opening'                                                                                   => 'פתיחה',
        'Our products'                                                                              => 'המוצרים שלנו',
        'View all products'                                                                         => 'לכל המוצרים',
        'Privacy policy'                                                                            => 'מדיניות פרטיות',
        'Terms of service'                                                                          => 'תנאי שימוש',
        'Shipping & returns'                                                                        => 'משלוחים והחזרות',
        '[your store name]'                                                                         => '[שם החנות]',
        '[your email address]'                                                                      => '[כתובת האימייל שלך]',
        '[your business address]'                                                                   => '[כתובת העסק]',
        'More to read'                                                                              => 'עוד לקריאה',
        'Blog'                                                                                      => 'בלוג',
        'All'                                                                                       => 'הכול',
        '← Blog'                                                                                    => '→ בלוג',
        'Cash on delivery'                                                                          => 'מזומן במסירה',
        'Pay with cash upon delivery.'                                                              => 'תשלום במזומן במסירה.',
        'Direct bank transfer'                                                                      => 'העברה בנקאית',
        'Make your payment directly into our bank account. Please use your Order ID as the payment reference. Your order will not be shipped until the funds have cleared in our account.' => 'יש להעביר את התשלום ישירות לחשבון הבנק שלנו, עם מספר ההזמנה כאסמכתא. ההזמנה תישלח אחרי שהכסף יתקבל בחשבון.',
        'Standard delivery'                                                                         => 'משלוח רגיל',
        'Free shipping'                                                                             => 'משלוח חינם',
        'Pickup'                                                                                    => 'איסוף עצמי',
        'Submit'                                                                                    => 'שליחה',
        '← Blog'                                                                                    => '→ בלוג',
        'All posts'                                                                                 => 'כל הפוסטים',
        'Days'                                                                                      => 'ימים',
        'Hours'                                                                                     => 'שעות',
        'Minutes'                                                                                   => 'דקות',
        'Seconds'                                                                                   => 'שניות',
        'This offer has ended.'                                                                     => 'המבצע הסתיים.',
        'View all'                                                                                  => 'לכל המוצרים',
        'Cart'                                                                                      => 'עגלה',
        'Checkout'                                                                                  => 'קופה',
        'My account'                                                                                => 'החשבון שלי',
        'Shop'                                                                                      => 'חנות',
        'Uncategorized'                                                                             => 'ללא קטגוריה',
    ];
}
