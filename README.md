# Orchestrator Extension for Paymenter

This extension allows Paymenter to orchestrate provisioning across multiple existing Server extensions using slot-based capacity and maintenance-aware pool routing.

## Features

- ✅ Provision services through existing server integrations (no direct panel API required)
- ✅ Route deployments by available slot capacity
- ✅ Exclude servers in maintenance mode
- ✅ Support multiple allocation strategies (Most Free Slots, Round Robin)
- ✅ Keep services pending when no capacity is available
- ✅ Mark and classify pending reasons (`payment`, `no_capacity`, `error`, `creating`)
- ✅ Manual deploy page for pending services
- ✅ Pass-through actions to target provider (including common control-panel/login actions)

## Requirements

- Paymenter with Server extensions already configured (Virtualmin, Pterodactyl, Convoy, etc.)
- At least one Paymenter Server to use this `Orchestrator` extension
- At least one target Paymenter Server mapped in Orchestrator Pools
- Queue worker running for create jobs (recommended for production)

## Installation

1. Copy this extension to `extensions/Servers/Orchestrator` in your Paymenter installation
2. Go to the Paymenter administration panel
3. Navigate to Extensions > Servers
4. Find and enable the `Orchestrator` extension

## Configuration

### Extension Configuration

After enabling the extension, configure:

- **Allocation Strategy**:
	- `Most Free Slots` (default): selects the pool with most free slots
	- `Round Robin`: selects least recently assigned eligible pool

### Product Configuration

When creating a product using Orchestrator, set:

- **Required Slots**: Number of slots consumed by each service
- **Target Plan / Package Name**: Plan/package value sent to the target server extension

### Checkout Configuration

Clients will need to provide:

- **Domain**: Domain required for provisioning in the target extension flow

## How It Works

### Provisioning Flow

When a service create job runs, Orchestrator:
1. Sets provisioning state to `creating`
2. Resolves `required_slots`
3. Finds or allocates a compatible pool for the product's orchestrator server
4. Delegates `createServer()` to the selected target server extension
5. Stores allocation metadata in service properties
6. Sets service `active` only after successful target provisioning

If no capacity is available:
- Service remains `pending`
- Pending reason is marked as `no_capacity`
- The create flow throws an exception (job fails), preventing misleading "server created" notifications

If target provisioning fails:
- Service remains `pending`
- Pending reason is marked as `error`

### Pending Services (Manual Deploy)

The pending deploy page includes:

- **Single-select pending filter**:
	- `All pending`
	- `Payment required`
	- `Creation error`
	- `No capacity`
	- `Creating now`
- **Reason column** to quickly identify why each service is pending
- **Deploy action** to manually assign and deploy a pending service

### Pool Management

Orchestrator Pools define:

- Which target server can receive services
- Slot capacity (`total_slots`)
- Maintenance state

Slots are reserved per service allocation and released when the service is terminated.

## Notes

- Orchestrator depends on target server extensions to perform provider-specific provisioning.
- Ensure target plans/packages exist in the target system and match your `target_plan` values.
- If Retry is queued, `creating` will be reflected when the worker actually starts execution.

## Contributing

Contributions are welcome.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This extension is distributed under the GNU General Public License v3.0 (GNU GPLv3).

## Issues

If you encounter any issues or have suggestions, please [open an issue](https://github.com/santydesignscr/Multi-Server-Provisioner-Paymenter-Extension/issues) on GitHub.

## Support

For extension-related questions:
- Paymenter Discord: https://discord.gg/paymenter

---

**Developed by Santiago Rodriguez** | [GitHub](https://github.com/santydesignscr)
