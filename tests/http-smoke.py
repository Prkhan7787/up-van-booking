"""Run with a development server already listening on 127.0.0.1:8088."""
import urllib.request
import urllib.error

checks = [('/', 200, None), ('/assets/app.css', 200, None),
          ('/assets/sweetalert2.min.js', 200, None), ('/.env', 404, None),
          ('/config/database.php', 404, None), ('/storage/', 404, None),
          ('/missing', 404, None), ('/', 419, b''), ('/', 419, b'_token=wrong')]
for path, expected, data in checks:
    try:
        response = urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8088' + path, data=data), timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == expected, (path, response.status)
    if path == '/' and data is None:
        assert "default-src 'self'" in response.headers['Content-Security-Policy']
        assert 'HttpOnly' in response.headers['Set-Cookie']
        assert 'SameSite=Lax' in response.headers['Set-Cookie']
        assert 'ระบบขออนุญาต' in response.read().decode()
    print('PASS', 'POST' if data is not None else 'GET', path, response.status)
