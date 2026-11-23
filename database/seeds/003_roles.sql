-- Fixed role reference data only; no user accounts or test data.
INSERT INTO roles (name, description) VALUES
('requester', 'ผู้ขอใช้รถ'),
('vehicle_officer', 'เจ้าหน้าที่จัดรถ'),
('approver', 'ผู้พิจารณาอนุมัติ'),
('admin', 'ผู้ดูแลระบบ');
