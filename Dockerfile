
# --- ensure Laravel storage symlink inside the image ---
RUN rm -rf /var/www/html/public/storage \
 && ln -s /var/www/html/storage/app/public /var/www/html/public/storage

# (optional) make sure storage/cache are writable
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache || true
