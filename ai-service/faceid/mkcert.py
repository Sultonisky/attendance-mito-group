"""Bikin self-signed cert buat HTTPS lokal (port 8443).

Dijalanin ulang kalau IP laptop berubah (DHCP): python mkcert.py
"""
import datetime
import ipaddress
import socket
from pathlib import Path

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

HERE = Path(__file__).resolve().parent
OUT = HERE / "certs"
OUT.mkdir(exist_ok=True)

ips = {ipaddress.ip_address("127.0.0.1")}
try:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.connect(("8.8.8.8", 80))
    ips.add(ipaddress.ip_address(s.getsockname()[0]))
    s.close()
except Exception:
    pass
host = socket.gethostname()
try:
    for info in socket.getaddrinfo(host, None, socket.AF_INET):
        ips.add(ipaddress.ip_address(info[4][0]))
except Exception:
    pass

key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
now = datetime.datetime.now(datetime.timezone.utc)
name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "faceid-lab")])
san = [x509.DNSName("localhost"), x509.DNSName(host.lower())] + [
    x509.IPAddress(ip) for ip in sorted(ips)
]
cert = (
    x509.CertificateBuilder()
    .subject_name(name)
    .issuer_name(name)
    .public_key(key.public_key())
    .serial_number(x509.random_serial_number())
    .not_valid_before(now - datetime.timedelta(days=1))
    .not_valid_after(now + datetime.timedelta(days=825))
    .add_extension(x509.SubjectAlternativeName(san), critical=False)
    .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=False)
    .sign(key, hashes.SHA256())
)
(OUT / "server.pem").write_bytes(cert.public_bytes(serialization.Encoding.PEM))
(OUT / "key.pem").write_bytes(
    key.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.TraditionalOpenSSL,
        serialization.NoEncryption(),
    )
)
print("cert OK:", OUT / "server.pem")
print("IP di SAN:", ", ".join(str(i) for i in sorted(ips)))