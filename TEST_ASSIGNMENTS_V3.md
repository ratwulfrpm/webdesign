# Test Plan: Assignments V3 - Profit Refactor + Max Visits

## Changes Summary
- ✅ Profit section: Converted from preset buttons (5%, 10%, 15%, 20%, 30%) to 3-button interface (None/Percentage/Fixed Amount)
- ✅ Profit storage: Updated to store `profit_calculation_type` (percentage|fixed_amount) + either `profit_percentage` or `profit_fixed_amount`
- ✅ Max Visits: Added functionality to limit number of times a quote link can be viewed
- ✅ Backend validation: POST handler validates profit type and max_visits
- ✅ Database: Migration includes new max_visits INT UNSIGNED NULL column
- ✅ API: _createAssignment() and _cloneAssignment() handle max_visits parameter
- ✅ Public API: public_quote.php validates max_visits before returning quote
- ✅ i18n: Added keys for max_visits across en/es/zh languages

## Test Cases

### 1. Form UI Tests

#### 1.1 Profit Section Rendering
- [ ] Page loads admin/assignments.php
- [ ] Three profit buttons visible: "None", "Percentage", "Fixed Amount"
- [ ] No preset percentage buttons (5%, 10%, 15%, 20%, 30%) visible
- [ ] No "Free profit" button visible
- [ ] Profit input rows hidden by default

#### 1.2 Profit Button Interaction
- [ ] Click "Percentage" button → profitPctRow displays
- [ ] profitPctInput gets focus
- [ ] profit_percentage hidden field updates as user types
- [ ] Click "Fixed Amount" button → profitAmtRow displays
- [ ] profitAmtInput gets focus with $ prefix
- [ ] profit_fixed_amount hidden field updates as user types
- [ ] Click "None" button → both rows hide
- [ ] profit_calculation_type is cleared

#### 1.3 Max Visits Section Rendering
- [ ] Label "Maximum visits to link (optional)" visible
- [ ] Input field visible with placeholder "e.g. 5, 10, unlimited if empty"
- [ ] Help text visible "Once reached, the link will no longer work..."
- [ ] Field accepts positive integers
- [ ] Field accepts empty value (unlimited)
- [ ] max_visits hidden field updates on input

#### 1.4 Selected Panel Updates
- [ ] Select products in left panel
- [ ] Select FOB/CIF price base
- [ ] Select profit type and value
- [ ] Selected products panel shows prices with profit applied
- [ ] Price calculations match expected values

### 2. Form Submission Tests

#### 2.1 Validation
- [ ] Submit without selecting profit → error "Profit configuration is required"
- [ ] Select percentage profit without entering value → error on submit
- [ ] Select fixed amount profit without entering value → error on submit
- [ ] Enter negative profit → rejected/error
- [ ] Enter max_visits as negative → rejected/error
- [ ] Submit with valid profit and empty max_visits → accepted (unlimited)
- [ ] Submit with valid profit and max_visits=5 → accepted

#### 2.2 Form Data Submission
- [ ] POST includes profit_calculation_type correctly
- [ ] POST includes profit_percentage when type=percentage
- [ ] POST includes profit_fixed_amount when type=fixed_amount
- [ ] POST includes max_visits when set
- [ ] POST includes max_visits=null or empty when unlimited

### 3. Backend Tests

#### 3.1 Database Insertion
- [ ] Run migration: `php setup/run_migration.php`
- [ ] quote_assignments table has max_visits column
- [ ] Insert with profit_percentage and profit_calculation_type='percentage'
- [ ] Insert with profit_fixed_amount and profit_calculation_type='fixed_amount'
- [ ] Insert with max_visits=5
- [ ] Insert with max_visits=null

#### 3.2 API Response
- [ ] POST /api/v1/assignments with profit percentage → success
- [ ] POST /api/v1/assignments with profit fixed amount → success
- [ ] POST /api/v1/assignments with max_visits → quote_url returned
- [ ] GET /api/v1/assignments/:id returns max_visits field

### 4. Public Quote Tests

#### 4.1 View Count Tracking
- [ ] First view: view_count increments to 1
- [ ] Second view: view_count increments to 2
- [ ] Views tracked correctly in DB

#### 4.2 Max Visits Enforcement
- [ ] Create quote with max_visits=2
- [ ] First public access: success, returns quote
- [ ] Second public access: success, returns quote
- [ ] Third public access: error "max visits reached"
- [ ] Assignment status changes to 'expired'
- [ ] Quote no longer accessible via link

#### 4.3 Unlimited Visits
- [ ] Create quote with max_visits=null
- [ ] Access 10+ times: all succeed
- [ ] view_count increments each time

### 5. Edge Cases

#### 5.1 Profit Calculation
- [ ] Profit percentage=0% → final price = base price
- [ ] Profit percentage=100% → final price = base price * 2
- [ ] Profit fixed=$10 → final price = base price + $10
- [ ] Profit percentage and percentage stored correctly
- [ ] Profit fixed and fixed amount stored correctly

#### 5.2 Max Visits Edge Cases
- [ ] max_visits=1 → allows exactly 1 view then expires
- [ ] max_visits=999999 → very large value accepted
- [ ] max_visits=0 → rejected as invalid
- [ ] max_visits="" (empty string) → treated as unlimited (null)

### 6. Multilingual Tests

#### 6.1 Language Support
- [ ] English: "Profit" label, "Maximum visits" label visible
- [ ] Spanish: "Ganancia", "Máximo número de visitas" visible
- [ ] Chinese: Corresponding translations visible
- [ ] All i18n keys for profit and max_visits resolve correctly

### 7. Regression Tests

#### 7.1 Existing Features
- [ ] Transport percentage still works
- [ ] Transport fixed amount still works
- [ ] Tax percentage still works
- [ ] Tax fixed amount still works
- [ ] Validity hours/days still works
- [ ] Quote link expiry still works
- [ ] Clone assignment preserves settings
- [ ] Discount percentage still works

## SQL Migration Command
```bash
php setup/run_migration.php
```

## Key Files Modified
- admin/assignments.php: Profit UI + JavaScript + validation
- api/v1/resources/assignments.php: max_visits validation + insertion
- api/v1/resources/public_quote.php: max_visits enforcement
- lang/en.php, lang/es.php, lang/zh.php: i18n keys
- setup/migrate_assignments_fees_validity.sql: DB schema

## Expected Behavior Summary
- Profit section uses flexible 3-button interface (None/Percentage/Fixed Amount)
- Max visits is optional; null/empty means unlimited
- Once max_visits reached, quote link returns error 410 and marks assignment as expired
- All calculations work correctly for both percentage and fixed amount profits
- Multilingual support for new features works across 3 languages
