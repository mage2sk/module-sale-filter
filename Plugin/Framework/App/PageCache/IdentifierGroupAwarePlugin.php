<?php
declare(strict_types=1);

namespace Panth\SaleFilter\Plugin\Framework\App\PageCache;

use Magento\Framework\App\PageCache\Identifier;
use Magento\Framework\App\PageCache\IdentifierInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Serialize\Serializer\Json;

class IdentifierGroupAwarePlugin
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly Json $serializer
    ) {
    }

    public function aroundGetValue(IdentifierInterface $subject, \Closure $proceed): string
    {
        $cookieVary = $this->request->get(HttpResponse::COOKIE_VARY_STRING);
        if (!$cookieVary) {
            return (string) $proceed();
        }

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

    private function getMarketingPatterns(IdentifierInterface $subject): array
    {
        if ($subject instanceof Identifier) {
            return $subject->getMarketingParameterPatterns();
        }

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
