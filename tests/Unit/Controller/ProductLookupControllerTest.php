<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Tests\Unit\Controller;

use OCA\InventoryCheck\Controller\ProductLookupController;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\Lookup\LookupException;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ProductLookupControllerTest extends TestCase
{
	private function controller(IRequest $request, ProductLookupService $service): ProductLookupController
	{
		$access = $this->createMock(AccessControlService::class);
		$access->method('currentUserId')->willReturn('alice');
		$access->method('requireWrite')->willReturnCallback(static function (string $uid): void {
			self::assertSame('alice', $uid);
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		return new ProductLookupController($request, $access, $service, $l10n);
	}

	private function request(string $barcode): IRequest
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'barcode' ? $barcode : $default,
		);
		return $request;
	}

	public function testHitPassesThroughFlatPayload(): void
	{
		$payload = [
			'found' => true,
			'barcode' => '4006381333931',
			'name' => 'Widget',
			'brand' => null,
			'description' => null,
			'sourceUrl' => null,
			'provider' => 'openfoodfacts',
		];
		$service = $this->createMock(ProductLookupService::class);
		$service->expects($this->once())
			->method('lookup')
			->with('4006381333931', 'alice')
			->willReturn($payload);

		$res = $this->controller($this->request('4006381333931'), $service)->lookup();
		self::assertSame(200, $res->getStatus());
		self::assertSame($payload, $res->getData());
	}

	public function testLookupExceptionMapsToUniformErrorEnvelope(): void
	{
		foreach ([
			'invalid_barcode' => 422,
			'rate_limited' => 429,
			'feature_disabled' => 403,
			'unavailable' => 502,
		] as $code => $status) {
			$service = $this->createMock(ProductLookupService::class);
			$service->method('lookup')->willThrowException(new LookupException($code, 'x', $status));
			$res = $this->controller($this->request('4006381333931'), $service)->lookup();
			self::assertSame($status, $res->getStatus(), $code);
			self::assertSame($code, $res->getData()['error']['code']);
			self::assertNotSame('', $res->getData()['error']['message'], 'message must be localized, never the raw exception');
		}
	}

	public function testNonStringBarcodeIsInvalidBarcodeNotFatal(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'barcode' ? ['evil'] : $default,
		);
		$service = $this->createMock(ProductLookupService::class);
		$service->expects($this->never())->method('lookup');
		$res = $this->controller($request, $service)->lookup();
		self::assertSame(422, $res->getStatus());
		self::assertSame('invalid_barcode', $res->getData()['error']['code']);
	}
}
