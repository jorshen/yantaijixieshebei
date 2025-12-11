<?php get_header();?>
<div class="slider am-slider">
	<ul class="am-slides">
		<li class="slider_1"></li>
		<li class="slider_2"></li>
		<li class="slider_3"></li>
	</ul>
</div>
<script type="text/javascript">
$(function() {
  $('.slider').flexslider({
    // options
  });
});
</script>
<div class="product_category">
	<ul>
		<li><a href="/product/paixieqi/" target="_blank"><strong>排屑器</strong><span>Chip conveyor</span></a></li>
		<li><a href="/product/guolvji/" target="_blank"><strong>过滤机</strong><span>Filter</span></a></li>
		<li><a href="/product/fenliqi/" target="_blank"><strong>分离器</strong><span>Separator</span></a></li>
		<li><a href="/product/qingxiji/" target="_blank"><strong>清洗机</strong><span>Washing machine</span></a></li>
		<li><a href="/product/chuchenqi/" target="_blank"><strong>除尘器</strong><span>Dust collector</span></a></li>
		<li><a href="/product/zhengtifangan/" target="_blank"><strong>整体解决方案</strong><span>Overall solution</span></a></li>
	</ul>
</div>
<section class="box_1 product">
	<div class="bhead" data-am-scrollspy="{animation: 'slide-bottom'}">
		<strong>产品展示</strong>
		<span>Products</span>
		<em><a href="/product/" title="更多产品" target="_blank">更多产品</a></em>
	</div>
	<div class="bbody" data-am-scrollspy="{animation: 'slide-bottom',delay:200}">
	<?php $my_query = new WP_Query( array(
      'cat' => 3,  
    	'post__in' => get_option('sticky_posts'),
      'posts_per_page' =>6
  ) );query_posts($my_query); ?>
		<ul>
		<?php while ( $my_query->have_posts() ) : $my_query->the_post(); ?>
			<li>
				<figure>
				<?php if ( has_post_thumbnail() ) { ?>
					<a href="<?php the_permalink(); ?>"><img src="<?php the_post_thumbnail_url('thumbnail'); ?>" alt="<?php the_title(); ?>" /></a>
        <?php } else { ?>
					<a href="<?php the_permalink(); ?>"><img src="<?php echo get_template_directory_uri(); ?>/images/nopic.png" alt="<?php the_title(); ?>" /></a>
        <?php } ?>
					<figcaption><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></figcaption>
				</figure>
			</li>
			<?php endwhile; ?>
		</ul>
	</div>
</section>
<section class="box_about">
	<div class="box_2" data-am-scrollspy="{animation: 'slide-bottom'}">
		<div class="bhead">
			<strong>关于我们</strong>
		</div>
		<div class="bbody">
			<p>我公司位于山东烟台，凭借地理优势，专业生产各种水产品，产品出口达十年之久，质量有保证，价格有优势，公司理念质量第一，安全第一，信誉至上；持续改进，满足法规，追求卓越；客户满意，消费者放心欢迎中外客户来电咨询。</p>
		</div>
		<div class="bmore"><a href="/about/" title="查看更多" target="_blank">查看更多</a></div>
	</div>
</section>
<section class="box_1 news">
	<div class="bhead" data-am-scrollspy="{animation: 'slide-bottom'}">
		<strong>新闻中心</strong>
		<span>News</span>
		<em><a href="/news/" title="更多新闻" target="_blank">更多新闻</a></em>
	</div>
	<div class="bbody" data-am-scrollspy="{animation: 'slide-bottom',delay:200}">
	<?php $my_query = new WP_Query( array(
      'cat' => 5,
      'posts_per_page' =>4
  ) );query_posts($my_query); ?>
		<ul>
		<?php while ( $my_query->have_posts() ) : $my_query->the_post(); ?>
			<li>
				<dl>
					<dt><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></dt>
					<dd><?php echo mb_strimwidth(strip_tags($post->post_content),0,150,'...'); ?></dd>
					<dd class="date">
						<strong><?php the_time('d') ?></strong>
						<span><?php the_time('Y-m') ?></span>
					</dd>
					<dd class="more"><a href="/news/92.html" title="查看详情" target="_blank">查看详情</a></dd>
				</dl>
			</li>
			<?php endwhile; ?>
		</ul>
	</div>
</section>
<section class="box_map">
	<iframe src="<?php echo get_template_directory_uri(); ?>/map.html" scrolling="no" frameborder="0"></iframe>
	<div class="box_contact" data-am-scrollspy="{animation: 'slide-bottom'}">
		<dl>
			<dt>烟台市新宇过滤设备有限公司</dt>
			<dd>地址：烟台市芝罘区只楚镇港城西大街38号</dd>
			<dd>联系人：张经理</dd>
			<dd>电话：0535-6513570</dd>
			<dd>手机：13954528071</dd>
		</dl>
		<div class="more"><a href="/contact/" title="查看详情" target="_blank">查看详情</a></div>
	</div>
</section>
<?php get_footer();?>