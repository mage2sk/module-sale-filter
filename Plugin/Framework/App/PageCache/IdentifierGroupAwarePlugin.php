<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Framework\App\PageCache;

use Magento\Framework\App\PageCache\Identifier;
use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Restore pre-2.4.7 cookie-aware FPC keying so logged-in customer groups
 * don't get served the guest page from the built-in FPC.
 *
 * Magento 2.4.7 moved FPC identifier logic to IdentifierForSave which
 * keys only on $context->getVaryString() — empty at LOAD time because the
 * customer ContextPlugin runs on beforeExecute (during controller dispatch)
 * while FPC load happens earlier in aroundDispatch. Net effect on built-in
 * FPC: whichever user warms the cache for a URL dictates what every other
 * user sees. The SaleFilter block renders different counts per customer
 * group, so a guest-warmed page loses the "Yes" option for logged-in
 * General / Wholesale / Retailer shoppers even though their group has
 * matching catalog rules.
 *
 * This plugin reads X-Magento-Vary straight off the incoming request cookie
 * (exactly what the pre-2.4.7 Identifier::getValue did) and mixes it into
 * the cache key so each group ends up with its own FPC entry. Harmless on
 * guest-only traffic (no cookie → falls through to core behavior).
 *
 * Wired globally on IdentifierInterface in etc/di.xml so both Identifier
 * and IdentifierForSave (the save-side subclass that Kernel uses for both
 * load and save) get covered.
 */
class IdentifierGroupAwarePlugin
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly Json $serializer
    ) {
    }

    /**
     * Around-plugin on getValue() so we can fully replace the hash input
     * with the cookie-based vary when present. Using afterGetValue would
     * produce different LOAD vs SAVE keys within a single request because
     * the core result itself differs (LOAD has empty context, SAVE has
     * populated context) — mixing the cookie in afterwards cannot undo
     * that.
     *
     * @param IdentifierInterface $subject
     * @param \Closure $proceed
     * @return string
     */
    public function aroundGetValue(IdentifierInterface $subject, \Closure $proceed): string
    {
        $cookieVary = $this->request->get(HttpResponse::COOKIE_VARY_STRING);
        if (!$cookieVary) {
            // No vary cookie on the request — use core behavior. First-time
            // visitor's SAVE will still land at the same key as core LOAD,
            // since neither sees the cookie. Once the response sets the
            // vary cookie, subsequent requests hit the branch below.
            return (string) $proceed();
        }

        // Replicate the pre-2.4.7 Identifier::getValue but with the cookie
        // value in the vary slot. Keeps LOAD and SAVE consistent within a
        // single request because the cookie is stable from request arrival
        // to response dispatch.
        $pattern = $this->getMarketingPatterns($subject);
        $replace = array_fill(0, count($pattern), '');
        $data    = [
            $this->request->isSecure(),
            preg_replace(
                $pattern,
                $replace,
                (string) $this->request->getUriString()
            ),
            (string) $cookieVary,
        ];

        return sha1($this->serializer->serialize($data));
    }

    /**
     * Pull marketing-param patterns from the underlying Identifier so the
     * URL portion of the key stays aligned with core behavior (strips utm_*,
     * gclid, fbclid, etc. before hashing).
     */
    private function getMarketingPatterns(IdentifierInterface $subject): array
    {
        if ($subject instanceof Identifier) {
            return $subject->getMarketingParameterPatterns();
        }
        // IdentifierForSave composes an Identifier via `$this->identifier`;
        // accessing it reflectively keeps us resilient if Adobe renames the
        // property.
        if (property_exists($subject, 'identifier')) {
            $ref = new \ReflectionProperty($subject, 'identifier');
            $ref->setAccessible(true);
            $inner = $ref->getValue($subject);
            if ($inner instanceof Identifier) {
                return $inner->getMarketingParameterPatterns();
            }
        }

        return [];
    }
}
