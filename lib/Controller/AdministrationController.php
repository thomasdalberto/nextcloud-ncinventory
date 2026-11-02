<?php

declare(strict_types=1);

namespace OCA\InventoryCheck\Controller;

use OCA\InventoryCheck\AppInfo\Application;
use OCA\InventoryCheck\Exception\ValidationException;
use OCA\InventoryCheck\Service\AccessControlService;
use OCA\InventoryCheck\Service\BoolParam;
use OCA\InventoryCheck\Service\ProductLookupService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** App-wide settings shown by the app navigation's footer, restricted to Nextcloud admins. */
class AdministrationController extends Controller
{
	use PolicyAuditTrait;

	public function __construct(
		IRequest $request,
		private readonly AccessControlService $access,
		private readonly ProductLookupService $productLookup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function index(): JSONResponse
	{
		$this->access->requireSystemAdmin($this->access->currentUserId());
		return new JSONResponse([
			'enabled' => $this->productLookup->isEnabled(),
			'autofire' => $this->productLookup->isAutofire(),
			'providers' => $this->productLookup->availableProviders(),
			'enabledProviders' => $this->productLookup->enabledProviderIds(),
			'stats' => $this->productLookup->stats(),
		]);
	}

	public function save(): JSONResponse
	{
		$uid = $this->access->currentUserId();
		$this->access->requireSystemAdmin($uid);
		$enabled = BoolParam::parse($this->request->getParam('enabled'), 'enabled');
		$autofire = BoolParam::parse($this->request->getParam('autofire'), 'autofire');
		$providers = $this->request->getParam('providers');
		if (!is_array($providers) || array_diff($providers, $this->productLookup->providerIds()) !== []) {
			throw new ValidationException('validation_failed', '', [['field' => 'providers', 'code' => 'unknown_provider']]);
		}
		foreach ($providers as $provider) {
			if (!is_string($provider)) { throw new ValidationException('validation_failed'); }
		}
		$this->productLookup->setEnabled($enabled);
		$this->productLookup->setAutofire($autofire);
		$this->productLookup->setEnabledProviders(array_values(array_unique($providers)));
		$this->auditPolicyChange($uid, 'product_lookup_saved');
		return $this->index();
	}
}
