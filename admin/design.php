<?php
// admin/design.php

// Užkrauname dizaino nustatymus
// Pastaba: getFooterLinks($pdo) pašalinta, nes nebenaudojama
$siteContent = getSiteContent($pdo);
?>

<style>
    .section-title { font-size: 16px; font-weight: 700; margin: 0 0 16px 0; color: var(--text-main); display:flex; align-items:center; gap:8px; border-bottom:1px solid #eee; padding-bottom:10px; }
    .form-group { margin-bottom: 12px; }
    .form-label { display: block; font-size: 11px; font-weight: 700; text-transform:uppercase; color: var(--text-muted); margin-bottom: 4px; }
    .form-control { width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background:#fff; }
    .form-control:focus { border-color: #4f46e5; outline: none; }
    textarea.form-control { resize: vertical; min-height: 80px; }
    
    .media-preview { margin-top:5px; font-size:11px; color:#6b7280; background:#f9fafb; padding:4px 8px; border-radius:4px; display:inline-block; border:1px solid #e5e7eb;}
    .color-picker-wrapper { display:flex; align-items:center; gap:10px; }
    .range-output { font-weight:700; font-size:13px; width:30px; text-align:right; }
    
    .sub-card { background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
    .sub-card-title { font-size: 13px; font-weight: 700; color: #334155; margin: 0 0 10px 0; text-transform:uppercase; }
</style>

<div class="grid grid-2">

    <div class="card">
        <h3 class="section-title">🏠 Titulinio Hero Sekcija</h3>
        
        <form method="post" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="hero_copy">
            
            <div class="form-group">
                <label class="form-label">Antraštė (H1)</label>
                <input name="hero_title" value="<?php echo htmlspecialchars($siteContent['hero_title'] ?? ''); ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Aprašymas</label>
                <textarea name="hero_body" class="form-control"><?php echo htmlspecialchars($siteContent['hero_body'] ?? ''); ?></textarea>
            </div>
            <div class="grid grid-2" style="gap:10px;">
                <div class="form-group">
                    <label class="form-label">Mygtuko tekstas</label>
                    <input name="hero_cta_label" value="<?php echo htmlspecialchars($siteContent['hero_cta_label'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Mygtuko nuoroda</label>
                    <input name="hero_cta_url" value="<?php echo htmlspecialchars($siteContent['hero_cta_url'] ?? ''); ?>" class="form-control">
                </div>
            </div>
            <div style="text-align:right;">
                <button class="btn secondary" type="submit">Atnaujinti tekstus</button>
            </div>
        </form>

        <div style="margin-top:20px; padding-top:20px; border-top:1px dashed #e5e7eb;">
            <form method="post" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="hero_media_update">
                
                <h4 style="font-size:14px; margin:0 0 15px 0;">Hero Fonas ir Media</h4>
                
                <div class="grid grid-2" style="gap:15px;">
                    <div>
                        <div class="form-group">
                            <label class="form-label">Fono tipas</label>
                            <select name="hero_media_type" class="form-control">
                                <?php $selectedType = $siteContent['hero_media_type'] ?? 'image'; ?>
                                <option value="image" <?php echo $selectedType === 'image' ? 'selected' : ''; ?>>Nuotrauka</option>
                                <option value="video" <?php echo $selectedType === 'video' ? 'selected' : ''; ?>>Video</option>
                                <option value="color" <?php echo $selectedType === 'color' ? 'selected' : ''; ?>>Tik spalva</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Overlay spalva</label>
                            <div class="color-picker-wrapper">
                                <input name="hero_media_color" type="color" value="<?php echo htmlspecialchars($siteContent['hero_media_color'] ?? '#829ed6'); ?>" style="height:38px; padding:0; border:none; width:50px;">
                                <span style="font-size:12px; color:#666;">Fono/uždangos spalva</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Šešėlio intensyvumas (%)</label>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <input name="hero_shadow_intensity" type="range" min="0" max="100" style="flex:1;" 
                                       value="<?php echo (int)($siteContent['hero_shadow_intensity'] ?? 70); ?>" 
                                       oninput="this.nextElementSibling.innerText=this.value">
                                <span class="range-output"><?php echo (int)($siteContent['hero_shadow_intensity'] ?? 70); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="form-group">
                            <label class="form-label">Nuotrauka (jei pasirinkta)</label>
                            <input type="hidden" name="hero_media_image_existing" value="<?php echo htmlspecialchars($siteContent['hero_media_image'] ?? ''); ?>">
                            <input type="file" name="hero_media_image" accept="image/*" class="form-control" style="font-size:11px;">
                            <?php if (!empty($siteContent['hero_media_image'])): ?>
                                <div class="media-preview">Yra: ...<?php echo substr($siteContent['hero_media_image'], -20); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Video (jei pasirinkta)</label>
                            <input type="hidden" name="hero_media_video_existing" value="<?php echo htmlspecialchars($siteContent['hero_media_video'] ?? ''); ?>">
                            <input type="file" name="hero_media_video" accept="video/*" class="form-control" style="font-size:11px;">
                            <?php if (!empty($siteContent['hero_media_video'])): ?>
                                <div class="media-preview">Yra įkeltas video</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div style="text-align:right; margin-top:10px;">
                    <button class="btn" type="submit">Išsaugoti media</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="height:fit-content;">
        <h3 class="section-title">📢 Viršutinė reklamjuostė</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="banner_update">
            
            <div class="form-group" style="background:#fff7ed; padding:10px; border-radius:6px; border:1px solid #ffedd5;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:13px;">
                    <input type="checkbox" name="banner_enabled" <?php echo !empty($siteContent['banner_enabled']) && $siteContent['banner_enabled'] !== '0' ? 'checked' : ''; ?>>
                    Įjungti reklamjuostę
                </label>
            </div>
            
            <div class="form-group">
                <label class="form-label">Tekstas</label>
                <input name="banner_text" value="<?php echo htmlspecialchars($siteContent['banner_text'] ?? ''); ?>" placeholder="Pvz. Nemokamas pristatymas!" class="form-control">
            </div>
            
            <div class="grid grid-2" style="gap:10px;">
                <div class="form-group">
                    <label class="form-label">Nuoroda (nebūtina)</label>
                    <input name="banner_link" value="<?php echo htmlspecialchars($siteContent['banner_link'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Fono spalva</label>
                    <input type="color" name="banner_background" value="<?php echo htmlspecialchars($siteContent['banner_background'] ?? '#829ed6'); ?>" style="width:100%; height:35px; border:none; padding:0;">
                </div>
            </div>
            
            <div style="text-align:right;">
                <button class="btn" type="submit">Išsaugoti</button>
            </div>
        </form>
    </div>

</div>

<h3 style="margin: 30px 0 15px 0; font-size:18px;">Titulinio puslapio sekcijos</h3>

<div class="grid grid-2">
    
    <div class="card">
        <h3 class="section-title">⭐ Promo kortelės (3 vnt.)</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="promo_update">
            
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="sub-card">
                    <div class="sub-card-title">Kortelė #<?php echo $i; ?></div>
                    <div class="grid grid-2" style="gap:10px; margin-bottom:10px;">
                        <div>
                            <label class="form-label">Ikona/emoji</label>
                            <input name="promo_<?php echo $i; ?>_icon" value="<?php echo htmlspecialchars($siteContent['promo_' . $i . '_icon'] ?? ''); ?>" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Pavadinimas</label>
                            <input name="promo_<?php echo $i; ?>_title" value="<?php echo htmlspecialchars($siteContent['promo_' . $i . '_title'] ?? ''); ?>" class="form-control">
                        </div>
                    </div>
                    <label class="form-label">Tekstas</label>
                    <textarea name="promo_<?php echo $i; ?>_body" class="form-control" style="min-height:60px;"><?php echo htmlspecialchars($siteContent['promo_' . $i . '_body'] ?? ''); ?></textarea>
                </div>
            <?php endfor; ?>
            
            <div style="text-align:right;">
                <button class="btn" type="submit">Išsaugoti korteles</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="section-title">📘 Storyband (Istorija)</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="storyband_update">
            
            <div class="sub-card">
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="storyband_title" value="<?php echo htmlspecialchars($siteContent['storyband_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Aprašymas</label>
                    <textarea name="storyband_body" class="form-control"><?php echo htmlspecialchars($siteContent['storyband_body'] ?? ''); ?></textarea>
                </div>
                <div class="grid grid-2" style="gap:10px;">
                    <div class="form-group">
                        <label class="form-label">Mygtuko tekstas</label>
                        <input name="storyband_cta_label" value="<?php echo htmlspecialchars($siteContent['storyband_cta_label'] ?? ''); ?>" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mygtuko nuoroda</label>
                        <input name="storyband_cta_url" value="<?php echo htmlspecialchars($siteContent['storyband_cta_url'] ?? ''); ?>" class="form-control">
                    </div>
                </div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">Dešinė kortelė</div>
                <div class="form-group">
                    <label class="form-label">Kortelės antraštė</label>
                    <input name="storyband_card_title" value="<?php echo htmlspecialchars($siteContent['storyband_card_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Kortelės tekstas</label>
                    <textarea name="storyband_card_body" class="form-control"><?php echo htmlspecialchars($siteContent['storyband_card_body'] ?? ''); ?></textarea>
                </div>
            </div>
            
            <div style="text-align:right;">
                <button class="btn" type="submit">Išsaugoti Storyband</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="section-title">🌿 Story Row (Akcentai)</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="storyrow_update">
            
            <div class="form-group">
                <label class="form-label">Antraštė</label>
                <input name="storyrow_title" value="<?php echo htmlspecialchars($siteContent['storyrow_title'] ?? ''); ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Aprašymas</label>
                <textarea name="storyrow_body" class="form-control"><?php echo htmlspecialchars($siteContent['storyrow_body'] ?? ''); ?></textarea>
            </div>
            
            <div class="sub-card">
                <div class="sub-card-title">Sąrašo punktai (Pills)</div>
                <div class="form-group"><input name="storyrow_pill_1" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_1'] ?? ''); ?>" class="form-control"></div>
                <div class="form-group"><input name="storyrow_pill_2" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_2'] ?? ''); ?>" class="form-control"></div>
                <div class="form-group"><input name="storyrow_pill_3" value="<?php echo htmlspecialchars($siteContent['storyrow_pill_3'] ?? ''); ?>" class="form-control"></div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">Burbulo kortelė</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="storyrow_bubble_title" value="<?php echo htmlspecialchars($siteContent['storyrow_bubble_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Tekstas</label>
                    <textarea name="storyrow_bubble_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['storyrow_bubble_body'] ?? ''); ?></textarea>
                </div>
            </div>

            <div style="text-align:right;">
                <button class="btn" type="submit">Išsaugoti Story Row</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="section-title">🤝 Support Band (Pagalba)</h3>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="support_update">
            
            <div class="form-group">
                <label class="form-label">Antraštė</label>
                <input name="support_title" value="<?php echo htmlspecialchars($siteContent['support_title'] ?? ''); ?>" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Aprašymas</label>
                <textarea name="support_body" class="form-control"><?php echo htmlspecialchars($siteContent['support_body'] ?? ''); ?></textarea>
            </div>
            
            <div class="sub-card">
                <div class="sub-card-title">Temos (Chips)</div>
                <div class="form-group"><input name="support_chip_1" value="<?php echo htmlspecialchars($siteContent['support_chip_1'] ?? ''); ?>" class="form-control"></div>
                <div class="form-group"><input name="support_chip_2" value="<?php echo htmlspecialchars($siteContent['support_chip_2'] ?? ''); ?>" class="form-control"></div>
                <div class="form-group"><input name="support_chip_3" value="<?php echo htmlspecialchars($siteContent['support_chip_3'] ?? ''); ?>" class="form-control"></div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">Veiksmo kortelė</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="support_card_title" value="<?php echo htmlspecialchars($siteContent['support_card_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Tekstas</label>
                    <textarea name="support_card_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['support_card_body'] ?? ''); ?></textarea>
                </div>
                <div class="grid grid-2" style="gap:10px;">
                    <input name="support_card_cta_label" value="<?php echo htmlspecialchars($siteContent['support_card_cta_label'] ?? ''); ?>" class="form-control" placeholder="Mygtukas">
                    <input name="support_card_cta_url" value="<?php echo htmlspecialchars($siteContent['support_card_cta_url'] ?? ''); ?>" class="form-control" placeholder="Nuoroda">
                </div>
            </div>

            <div style="text-align:right;">
                <button class="btn" type="submit">Išsaugoti Support</button>
            </div>
        </form>
    </div>

</div>

<div class="card" style="margin-top:24px;">
    <h3 class="section-title">💬 Atsiliepimai (3 vnt.)</h3>
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="testimonial_update">
        
        <div class="grid grid-3">
            <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="sub-card">
                    <div class="sub-card-title">Klientas #<?php echo $i; ?></div>
                    <div class="form-group">
                        <label class="form-label">Vardas</label>
                        <input name="testimonial_<?php echo $i; ?>_name" value="<?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_name'] ?? ''); ?>" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rolė/Pareigos</label>
                        <input name="testimonial_<?php echo $i; ?>_role" value="<?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_role'] ?? ''); ?>" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Atsiliepimas</label>
                        <textarea name="testimonial_<?php echo $i; ?>_text" class="form-control" style="min-height:80px;"><?php echo htmlspecialchars($siteContent['testimonial_' . $i . '_text'] ?? ''); ?></textarea>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        <div style="text-align:right;">
            <button class="btn" type="submit">Išsaugoti atsiliepimus</button>
        </div>
    </form>
</div>

<h3 style="margin: 30px 0 15px 0; font-size:18px;">Vidinių puslapių Hero</h3>
<div class="card">
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="page_hero_update">
        
        <div class="grid grid-2">
            <div class="sub-card">
                <div class="sub-card-title">📰 Naujienos</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="news_hero_title" value="<?php echo htmlspecialchars($siteContent['news_hero_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Aprašymas</label>
                    <textarea name="news_hero_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['news_hero_body'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">🍳 Receptai</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="recipes_hero_title" value="<?php echo htmlspecialchars($siteContent['recipes_hero_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Aprašymas</label>
                    <textarea name="recipes_hero_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['recipes_hero_body'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">❓ DUK</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="faq_hero_title" value="<?php echo htmlspecialchars($siteContent['faq_hero_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Aprašymas</label>
                    <textarea name="faq_hero_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['faq_hero_body'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="sub-card">
                <div class="sub-card-title">📞 Kontaktai</div>
                <div class="form-group">
                    <label class="form-label">Antraštė</label>
                    <input name="contact_hero_title" value="<?php echo htmlspecialchars($siteContent['contact_hero_title'] ?? ''); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Aprašymas</label>
                    <textarea name="contact_hero_body" class="form-control" style="min-height:50px;"><?php echo htmlspecialchars($siteContent['contact_hero_body'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <div style="text-align:right;">
            <button class="btn" type="submit">Išsaugoti vidinius Hero</button>
        </div>
    </form>
</div>
