<div class="banner banner_2"></div>
<div class="main">
	<div class="position">当前位置：<?php if(function_exists('bcn_display'))
    {
      bcn_display();
    }?></div>
	<div class="mr">
		<div class="page_title"><strong><?php single_cat_title(); ?></strong></div>
		<div class="page_main">
    <?php $my_query=new WP_Query(array(
    'cat'				=>get_query_var('cat'),
    'paged'				=>get_query_var('paged'),
    ))?>
    <?php if($my_query->have_posts()):while($my_query->have_posts()):$my_query->the_post();?>
      <div class="article_article">
				<div class="article_body">
        <?php the_content(); ?>
				</div>
			</div>
      <?php endwhile; ?>
        <?php else: ?>
        <p>暂无内容</p>
        <?php endif; ?>
		</div>
	</div>
	<div class="ml">
		<div class="category">
	<div class="catname">
		<strong>产品展示</strong>
	</div>
	<div class="subcat">
		<ul>
    <?php $args=array('orderby' => 'ID','order' => 'ASC','child_of' => 3,'hide_empty'=>0);
    $categories=get_categories($args);
    foreach($categories as $category) {
        if(($category->term_id)==$cat){
            echo '<li class="current"><a href="'.get_category_link($category->term_id).'" target="_self">'.$category->name.'</a></li>';
        }else{
            echo '<li><a href="'.get_category_link($category->term_id).'" target="_self">'.$category->name.'</a></li>';
        }
    }
    ?>
		</ul>
	</div>
</div>
	</div>
</div>