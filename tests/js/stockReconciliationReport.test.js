import test from 'node:test';
import assert from 'node:assert/strict';

import {
    isAlreadyKeyed,
    parseProposalPayload,
    prepareReconciliationReport,
} from '../../resources/js/stockReconciliationReport.js';

const stockClass = {
    id: 1,
    name: 'Lambs',
    opening_count: 100,
    closing_count: 90,
    movements: [
        { type: 'sale', quantity: 10, note: 'Docket S-100, 1 May 2026' },
    ],
};

test('already-keyed detection requires the same source, not only type and quantity', () => {
    assert.equal(isAlreadyKeyed(stockClass, {
        type: 'sale',
        quantity: 10,
        note: 'Docket S-100, 1 May 2026',
    }), true);

    assert.equal(isAlreadyKeyed(stockClass, {
        type: 'sale',
        quantity: 10,
        note: 'Docket S-101, 7 May 2026',
    }), false);
});

test('report keeps a distinct transaction with the same type and quantity', () => {
    const proposal = parseProposalPayload({
        proposals: [{
            record_ids: [2],
            stock_class: 'Lambs',
            stock_class_id: 1,
            type: 'sale',
            confidence: 0.99,
            quantity: 10,
            note: 'Docket S-101, 7 May 2026',
            include: true,
            flag: null,
            reasoning: 'A separate sale docket.',
        }],
    });

    const report = prepareReconciliationReport([stockClass], proposal);

    assert.equal(report.counts.added_to_report, 1);
    assert.equal(report.counts.already_keyed, 0);
    assert.equal(report.classes[0].sales, 20);
    assert.equal(report.classes[0].calculated_closing, 80);
});

test('proposal parser rejects string booleans and fractional quantities', () => {
    const base = {
        record_ids: [1],
        stock_class: 'Lambs',
        stock_class_id: 1,
        type: 'sale',
        confidence: 0.9,
        quantity: 2,
        note: 'Docket S-100',
        include: true,
    };

    assert.throws(
        () => parseProposalPayload({ proposals: [{ ...base, include: 'false' }] }),
        /Boolean include/,
    );
    assert.throws(
        () => parseProposalPayload({ proposals: [{ ...base, quantity: 2.5 }] }),
        /invalid quantity/,
    );
});

test('response mode metadata does not affect proposal parsing', () => {
    const proposals = parseProposalPayload({
        ai_mode: 'demo',
        meta: { source: 'recorded_fixture' },
        proposals: [{
            record_ids: [1],
            stock_class: 'Lambs',
            stock_class_id: 1,
            type: 'death',
            confidence: 0.95,
            quantity: 2,
            note: 'Diary 1 May 2026',
            include: true,
            flag: null,
            reasoning: 'Two lambs died.',
        }],
        unresolved: [],
    });

    assert.equal(proposals.length, 1);
    assert.equal(proposals[0].quantity, 2);
});
