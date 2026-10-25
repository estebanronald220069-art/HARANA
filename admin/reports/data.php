<?php
// admin/reports/data.php
// All database queries for reports

function getStats() {
    $db = Database::getInstance();
    
    $total_collections = $db->getSingle("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'confirmed'")['total'] ?? 0;
    
    $current_month_collections = $db->getSingle("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE payment_status = 'confirmed' 
        AND MONTH(payment_date) = MONTH(CURDATE()) 
        AND YEAR(payment_date) = YEAR(CURDATE())
    ")['total'] ?? 0;
    
    $total_active_members = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;
    
    $pending_payments = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'pending'")['cnt'] ?? 0;
    
    $expected_monthly = $total_active_members * 100;
    $collection_rate = $expected_monthly > 0 ? round(($current_month_collections / $expected_monthly) * 100, 1) : 0;
    
    $overdue_accounts = $db->getSingle("
        SELECT COUNT(DISTINCT m.member_code) as cnt
        FROM members m
        LEFT JOIN member_balances b ON m.member_code = b.member_code
        WHERE m.status = 'active' AND (b.current_balance > 0 OR b.current_balance IS NULL)
    ")['cnt'] ?? 0;
    
    return [
        'total_collections' => $total_collections,
        'current_month' => $current_month_collections,
        'active_members' => $total_active_members,
        'pending_payments' => $pending_payments,
        'collection_rate' => $collection_rate,
        'overdue_accounts' => $overdue_accounts
    ];
}

function getChapters() {
    $db = Database::getInstance();
    return $db->getAll("SELECT DISTINCT chapter FROM members WHERE status = 'active' AND chapter IS NOT NULL AND chapter != '' ORDER BY chapter ASC");
}

function getChartData() {
    $db = Database::getInstance();
    
    // Monthly Collections
    $monthly_data = [];
    for ($i = 11; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $month_name = date('M Y', strtotime("-$i months"));
        $total = $db->getSingle("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM payments 
            WHERE payment_status = 'confirmed' 
            AND DATE_FORMAT(payment_date, '%Y-%m') = ?
        ", [$month], 's')['total'] ?? 0;
        $monthly_data[] = ['month' => $month_name, 'total' => $total];
    }
    
    // Payment Methods
    $payment_methods = $db->getAll("
        SELECT payment_method, COUNT(*) as count, SUM(amount) as total
        FROM payments 
        WHERE payment_status = 'confirmed'
        GROUP BY payment_method
    ");
    
    // Member Growth
    $member_growth = [];
    for ($i = 11; $i >= 0; $i--) {
        $month_end = date('Y-m-t', strtotime("-$i months"));
        $month_name = date('M Y', strtotime("-$i months"));
        $count = $db->getSingle("
            SELECT COUNT(*) as cnt 
            FROM members 
            WHERE created_at <= ?
        ", [$month_end], 's')['cnt'] ?? 0;
        $member_growth[] = ['month' => $month_name, 'count' => $count];
    }
    
    // Chapter Performance
    $chapter_performance = $db->getAll("
        SELECT 
            m.chapter,
            COUNT(DISTINCT m.member_code) as member_count,
            COALESCE(SUM(p.amount), 0) as total_collected,
            COALESCE(SUM(p.amount), 0) / GREATEST(COUNT(DISTINCT m.member_code) * 100, 1) * 100 as collection_rate
        FROM members m
        LEFT JOIN payments p ON m.member_code = p.member_id AND p.payment_status = 'confirmed'
        WHERE m.status = 'active' AND m.chapter IS NOT NULL AND m.chapter != ''
        GROUP BY m.chapter
        ORDER BY total_collected DESC
        LIMIT 10
    ");
    
    // Top Payers
    $top_payers = $db->getAll("
        SELECT 
            m.member_code,
            CONCAT(m.first_name, ' ', m.last_name) as member_name,
            m.chapter,
            COALESCE(SUM(p.amount), 0) as total_paid,
            COUNT(p.payment_id) as payment_count
        FROM members m
        LEFT JOIN payments p ON m.member_code = p.member_id AND p.payment_status = 'confirmed'
        WHERE m.status = 'active'
        GROUP BY m.member_code
        HAVING total_paid > 0
        ORDER BY total_paid DESC
        LIMIT 10
    ");
    
    // Aging Report
    $aging_report = $db->getAll("
        SELECT 
            m.member_code,
            CONCAT(m.first_name, ' ', m.last_name) as member_name,
            m.chapter,
            m.monthly_contribution,
            TIMESTAMPDIFF(MONTH, COALESCE(b.last_payment_date, m.date_joined), CURDATE()) as months_overdue,
            COALESCE(b.current_balance, 0) as balance_due
        FROM members m
        LEFT JOIN member_balances b ON m.member_code = b.member_code
        WHERE m.status = 'active' 
        AND (b.current_balance > 0 OR b.current_balance IS NULL)
        ORDER BY months_overdue DESC
        LIMIT 20
    ");
    
    // New Members
    $new_members = $db->getAll("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as period,
            COUNT(*) as count
        FROM members
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY period DESC
        LIMIT 12
    ");
    
    // Inactive Members
    $inactive_members = $db->getAll("
        SELECT 
            member_code,
            CONCAT(first_name, ' ', last_name) as member_name,
            chapter,
            status,
            inactive_date,
            inactive_reason
        FROM members
        WHERE status IN ('inactive', 'deceased')
        ORDER BY inactive_date DESC
        LIMIT 20
    ");
    
    return [
        'monthly' => $monthly_data,
        'payment_methods' => $payment_methods,
        'member_growth' => $member_growth,
        'chapter_performance' => $chapter_performance,
        'top_payers' => $top_payers,
        'aging_report' => $aging_report,
        'new_members' => $new_members,
        'inactive_members' => $inactive_members
    ];
}

function getReportData($view, $period, $filter_chapter, $filter_status, $date_from, $date_to, $page) {
    $db = Database::getInstance();
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    
    // Build filters
    $where = "1=1";
    $params = [];
    $types = '';
    
    if (!empty($filter_chapter)) {
        $where .= " AND chapter = ?";
        $params[] = $filter_chapter;
        $types .= 's';
    }
    if (!empty($filter_status)) {
        $where .= " AND status = ?";
        $params[] = $filter_status;
        $types .= 's';
    }
    
    $data = [];
    $total = 0;
    $columns = [];
    $title = '';
    
    switch ($view) {
        case 'financial':
            $title = 'Financial Summary Report';
            $columns = ['Month', 'Payment Method', 'Transactions', 'Total Amount'];
            $data = $db->getAll("
                SELECT 
                    DATE_FORMAT(payment_date, '%Y-%m') as month,
                    COUNT(*) as transaction_count,
                    SUM(amount) as total_amount,
                    payment_method
                FROM payments
                WHERE payment_status = 'confirmed'
                AND payment_date BETWEEN ? AND ?
                GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), payment_method
                ORDER BY payment_date DESC
                LIMIT ? OFFSET ?
            ", array_merge([$date_from, $date_to, $per_page, $offset], $params), 'ssii' . $types);
            $total = $db->getSingle("SELECT COUNT(DISTINCT DATE_FORMAT(payment_date, '%Y-%m')) as total FROM payments WHERE payment_status = 'confirmed' AND payment_date BETWEEN ? AND ?", [$date_from, $date_to], 'ss')['total'] ?? 0;
            break;
            
        case 'member':
            $title = 'Member Demographics Report';
            $columns = ['Chapter', 'Total Members', 'Male', 'Female', 'Average Age'];
            $data = $db->getAll("
                SELECT 
                    chapter,
                    COUNT(*) as member_count,
                    SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as female_count,
                    AVG(age) as avg_age
                FROM members
                WHERE status = 'active'
                GROUP BY chapter
                ORDER BY member_count DESC
                LIMIT ? OFFSET ?
            ", array_merge([$per_page, $offset], $params), 'ii' . $types);
            $total = $db->getSingle("SELECT COUNT(DISTINCT chapter) as total FROM members WHERE status = 'active'")['total'] ?? 0;
            break;
            
        case 'compliance':
            $title = 'Compliance Report';
            $columns = ['Code', 'Member', 'Chapter', 'Joined', 'Months', 'Beneficiary', 'Status'];
            $data = $db->getAll("
                SELECT 
                    m.member_code,
                    CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    m.chapter,
                    m.date_joined,
                    TIMESTAMPDIFF(MONTH, m.date_joined, CURDATE()) as membership_months,
                    m.beneficiary_name,
                    CASE 
                        WHEN m.beneficiary_name IS NOT NULL THEN 'Has Beneficiary'
                        ELSE 'No Beneficiary'
                    END as beneficiary_status,
                    CASE 
                        WHEN COALESCE(b.current_balance, 0) <= 0 THEN 'Compliant'
                        ELSE 'Non-Compliant'
                    END as payment_status
                FROM members m
                LEFT JOIN member_balances b ON m.member_code = b.member_code
                WHERE $where
                ORDER BY membership_months DESC
                LIMIT ? OFFSET ?
            ", array_merge($params, [$per_page, $offset]), $types . 'ii');
            $total = $db->getSingle("SELECT COUNT(*) as total FROM members m WHERE $where", $params, $types)['total'] ?? 0;
            break;
            
        case 'performance':
            $title = 'Performance Report';
            $columns = ['Chapter', 'Members', 'Total Collected', 'Collection Rate (%)'];
            $data = getChartData()['chapter_performance'];
            $total = count($data);
            break;
    }
    
    return [
        'data' => $data,
        'columns' => $columns,
        'title' => $title,
        'total' => $total,
        'per_page' => $per_page,
        'page' => $page,
        'total_pages' => $total > 0 ? ceil($total / $per_page) : 1
    ];
}