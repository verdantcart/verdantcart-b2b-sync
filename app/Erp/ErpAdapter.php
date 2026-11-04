<?php

namespace App\Erp;

use App\Models\OrderLog;

/**
 * What the sync pipeline needs from an ERP. One implementation per system (Fortnox first);
 * the rest of the app only talks to this interface.
 */
interface ErpAdapter
{
    /** Short id shown in logs and the dashboard, e.g. "fortnox". */
    public function name(): string;

    /**
     * Create the (already priced) order in the ERP.
     *
     * @return string the ERP's document number for the new order
     *
     * @throws ErpException
     */
    public function pushOrder(OrderLog $order): string;

    /**
     * All customers in the ERP.
     *
     * @return iterable<ErpCustomer>
     *
     * @throws ErpException
     */
    public function pullCustomers(): iterable;

    /**
     * Sellable stock per article, as the ERP sees it. Articles the ERP does not stock-manage
     * are left out; an empty result means "no ERP stock data, keep what you have".
     *
     * @param  list<string>  $articleNumbers
     * @return array<string, int> article number => quantity
     *
     * @throws ErpException
     */
    public function stockLevels(array $articleNumbers): array;
}
